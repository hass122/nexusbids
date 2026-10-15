<?php

session_start();

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/rate-limit.php";


if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: login.php");
    exit();
}

nb_csrf_check();

if (empty($_SESSION["google_pending"])) {
    header("Location: login.php");
    exit();
}

if (!nb_throttle("google_signup_" . ($_SERVER["REMOTE_ADDR"] ?? "unknown"), 5, 300)) {
    $_SESSION["error"] = "Too many signups from this connection. Please try again later.";
    header("Location: google-confirm.php");
    exit();
}

// The email comes from the verified Google profile stashed at
// callback time, never from the submitted form — the form's email
// field is disabled/display-only precisely so it can't be tampered
// with into claiming someone else's address.
$pending = $_SESSION["google_pending"];
$email = $pending["email"];

$full_name = trim($_POST["full_name"] ?? "");
if (empty($full_name)) {
    $full_name = $pending["name"];
}

$role = $_POST["role"] ?? "user";
if ($role !== "user" && $role !== "seller") {
    $role = "user";
}


// Re-check nobody registered this email in the few moments between
// the Google callback and this confirmation being submitted.
$check_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
$check_stmt->bind_param("s", $email);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows > 0) {
    unset($_SESSION["google_pending"]);
    $_SESSION["error"] = "This email is already registered. Please log in instead.";
    header("Location: login.php");
    exit();
}

// Google already verified this email address, so the account can go
// straight to active/verified. A random password is still set (rather
// than leaving the column empty) so the existing login/password-reset
// code keeps working unchanged if the person ever wants to add a
// password later via "Forgot password".
$random_password = bin2hex(random_bytes(32));
$hashed_password = password_hash($random_password, PASSWORD_DEFAULT);

$insert_stmt = $conn->prepare("
    INSERT INTO users
    (full_name, email, phone, password, role, email_verified, status)
    VALUES (?, ?, '', ?, ?, 1, 'active')
");

$insert_stmt->bind_param(
    "ssss",
    $full_name,
    $email,
    $hashed_password,
    $role
);

if (!$insert_stmt->execute()) {
    error_log("Google signup insert failed: " . $insert_stmt->error);
    $_SESSION["error"] = "Could not create your account. Please try again.";
    header("Location: google-confirm.php");
    exit();
}

$user_id = $insert_stmt->insert_id;

unset($_SESSION["google_pending"]);
session_regenerate_id(true);

$_SESSION["user_id"] = $user_id;
$_SESSION["full_name"] = $full_name;
$_SESSION["email"] = $email;
$_SESSION["role"] = $role;

if ($role === "seller") {
    header("Location: ../seller/dashboard.php");
} else {
    header("Location: ../user/dashboard.php");
}

exit();
