<?php

session_start();

require_once "../config/google-oauth.php";

// Random, single-use "state" value — Google echoes this back on the
// callback so we can confirm the response really came from a flow we
// started (protects against CSRF on the OAuth callback).
$state = bin2hex(random_bytes(32));
$_SESSION["google_oauth_state"] = $state;

// If the person clicked "Sign in with Google" from the register page
// with a role already selected, carry that through to the confirmation
// step so the pre-filled account type matches.
$_SESSION["google_oauth_role"] =
    (isset($_GET["role"]) && $_GET["role"] === "seller") ? "seller" : "user";

$params = [
    "client_id" => GOOGLE_CLIENT_ID,
    "redirect_uri" => GOOGLE_REDIRECT_URI,
    "response_type" => "code",
    "scope" => "openid email profile",
    "state" => $state,
    "prompt" => "select_account",
];

header("Location: https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query($params));
exit();
