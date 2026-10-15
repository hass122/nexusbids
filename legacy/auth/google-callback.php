<?php

session_start();

require_once "../config/database.php";
require_once "../config/google-oauth.php";
require_once "../includes/rate-limit.php";


function google_login_fail($message)
{
    $_SESSION["error"] = $message;
    header("Location: login.php");
    exit();
}


// The user cancelled on Google's consent screen, or Google is
// reporting some other error instead of a code.
if (isset($_GET["error"])) {
    google_login_fail("Google sign-in was cancelled.");
}

if (empty($_GET["code"]) || empty($_GET["state"])) {
    google_login_fail("Invalid Google sign-in request.");
}

// Confirm this callback matches a flow we actually started.
if (
    !isset($_SESSION["google_oauth_state"]) ||
    !hash_equals($_SESSION["google_oauth_state"], $_GET["state"])
) {
    google_login_fail("Your Google sign-in session expired. Please try again.");
}
unset($_SESSION["google_oauth_state"]);

if (!nb_throttle("google_login_" . ($_SERVER["REMOTE_ADDR"] ?? "unknown"), 10, 60)) {
    google_login_fail("Too many sign-in attempts. Please wait a minute and try again.");
}


/*
| Exchange the authorization code for an access token.
*/
$token_ch = curl_init("https://oauth2.googleapis.com/token");

curl_setopt_array($token_ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => http_build_query([
        "code" => $_GET["code"],
        "client_id" => GOOGLE_CLIENT_ID,
        "client_secret" => GOOGLE_CLIENT_SECRET,
        "redirect_uri" => GOOGLE_REDIRECT_URI,
        "grant_type" => "authorization_code",
    ]),
    CURLOPT_TIMEOUT => 15,
]);

$token_response = curl_exec($token_ch);
$token_curl_error = curl_error($token_ch);
curl_close($token_ch);

if ($token_response === false) {
    error_log("Google OAuth token request failed: " . $token_curl_error);
    google_login_fail("Could not reach Google right now. Please try again.");
}

$token_data = json_decode($token_response, true);

if (empty($token_data["access_token"])) {
    error_log("Google OAuth token response missing access_token: " . $token_response);
    google_login_fail("Google sign-in failed. Please try again.");
}


/*
| Use the access token to fetch the person's Google profile.
*/
$profile_ch = curl_init("https://www.googleapis.com/oauth2/v3/userinfo");

curl_setopt_array($profile_ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer " . $token_data["access_token"],
    ],
    CURLOPT_TIMEOUT => 15,
]);

$profile_response = curl_exec($profile_ch);
curl_close($profile_ch);

$profile = json_decode($profile_response, true);

if (empty($profile["email"])) {
    error_log("Google OAuth userinfo response missing email: " . $profile_response);
    google_login_fail("Could not read your Google profile. Please try again.");
}

if (empty($profile["email_verified"])) {
    google_login_fail("That Google account's email isn't verified. Please verify it with Google first.");
}

$google_email = trim($profile["email"]);
$google_name = trim($profile["name"] ?? explode("@", $google_email)[0]);


/*
| Existing NexusBids account with this email -> log straight in.
| New email -> hand off to a confirmation step before we create
| anything, rather than silently registering them.
*/
$stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $google_email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {

    $user = $result->fetch_assoc();

    if ($user["status"] !== "active") {
        google_login_fail("Your account is currently inactive or blocked.");
    }

    session_regenerate_id(true);

    $_SESSION["user_id"] = $user["user_id"];
    $_SESSION["full_name"] = $user["full_name"];
    $_SESSION["email"] = $user["email"];
    $_SESSION["role"] = $user["role"];

    if ($user["role"] === "admin") {
        header("Location: ../admin/index.php");
    } elseif ($user["role"] === "seller") {
        header("Location: ../seller/dashboard.php");
    } else {
        header("Location: ../user/dashboard.php");
    }

    exit();
}

// No account yet for this Google email — don't create one silently.
// Stash the verified profile in the session and send them to a
// confirmation screen; the account is only created once they confirm.
$_SESSION["google_pending"] = [
    "email" => $google_email,
    "name" => $google_name,
    "role" => $_SESSION["google_oauth_role"] ?? "user",
];
unset($_SESSION["google_oauth_role"]);

header("Location: google-confirm.php");
exit();
