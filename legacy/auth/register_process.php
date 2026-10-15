<?php

session_start();

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/rate-limit.php";
require_once "../includes/mailer.php";


if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    header("Location: register.php");
    exit();

}

nb_csrf_check();

if (!nb_throttle("register_" . ($_SERVER["REMOTE_ADDR"] ?? "unknown"), 5, 300)) {

    $_SESSION["error"] =
        "Too many signups from this connection. Please try again later.";

    header("Location: register.php");
    exit();
}


$full_name = trim($_POST["full_name"] ?? "");
$email = trim($_POST["email"] ?? "");
$phone = trim($_POST["phone"] ?? "");
$password = $_POST["password"] ?? "";
$confirm_password = $_POST["confirm_password"] ?? "";
$role = $_POST["role"] ?? "user";


if (
    empty($full_name) ||
    empty($email) ||
    empty($password) ||
    empty($confirm_password)
) {

    $_SESSION["error"] =
        "Please fill in all required fields.";

    header("Location: register.php");
    exit();

}


if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

    $_SESSION["error"] =
        "Please enter a valid email address.";

    header("Location: register.php");
    exit();

}


if (strlen($password) < 6) {

    $_SESSION["error"] =
        "Password must contain at least 6 characters.";

    header("Location: register.php");
    exit();

}


if ($password !== $confirm_password) {

    $_SESSION["error"] =
        "Passwords do not match.";

    header("Location: register.php");
    exit();

}


if (
    $role !== "user" &&
    $role !== "seller"
) {

    $role = "user";

}


$check_sql =
    "SELECT user_id
     FROM users
     WHERE email = ?";

$check_stmt =
    $conn->prepare($check_sql);

$check_stmt->bind_param(
    "s",
    $email
);

$check_stmt->execute();

$check_result =
    $check_stmt->get_result();


if ($check_result->num_rows > 0) {

    $_SESSION["error"] =
        "This email is already registered.";

    header("Location: register.php");
    exit();

}


$hashed_password =
    password_hash(
        $password,
        PASSWORD_DEFAULT
    );

$verify_token = bin2hex(random_bytes(32));


$insert_sql =
    "INSERT INTO users
    (
        full_name,
        email,
        phone,
        password,
        role,
        verify_token
    )
    VALUES (?, ?, ?, ?, ?, ?)";


$insert_stmt =
    $conn->prepare($insert_sql);


$insert_stmt->bind_param(
    "ssssss",
    $full_name,
    $email,
    $phone,
    $hashed_password,
    $role,
    $verify_token
);


if ($insert_stmt->execute()) {

    $verify_link =
        "http://" . $_SERVER["HTTP_HOST"] .
        dirname(dirname($_SERVER["SCRIPT_NAME"])) .
        "/auth/verify-email.php?token=" . $verify_token;

    nb_send_mail(
        $email,
        "Verify your NexusBids account",
        "<p>Hi " . htmlspecialchars($full_name) . ",</p>" .
        "<p>Please verify your email address to activate all features:</p>" .
        "<p><a href=\"" . htmlspecialchars($verify_link) . "\">Verify my email</a></p>"
    );

    $_SESSION["success"] =
        "Registration successful! Please check your email to verify your account, then login.";

    header("Location: login.php");
    exit();

} else {

    $_SESSION["error"] =
        "Registration failed. Please try again.";

    header("Location: register.php");
    exit();

}


$insert_stmt->close();

$conn->close();

?>
