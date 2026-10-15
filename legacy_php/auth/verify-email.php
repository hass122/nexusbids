<?php

session_start();

require_once "../config/database.php";

$token   = trim($_GET["token"] ?? "");
$message = "";
$success = false;

if (empty($token)) {

    $message = "Missing verification token.";

} else {

    $stmt = $conn->prepare("
        SELECT user_id, email_verified
        FROM users
        WHERE verify_token = ?
        LIMIT 1
    ");

    $stmt->bind_param("s", $token);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows === 0) {

        $message = "This verification link is invalid or has already been used.";

    } else {

        $user = $result->fetch_assoc();

        /* Already verified? */
        if (!empty($user["email_verified"])) {
            $success = true;
            $message = "Your email is already verified. You can log in.";
        } else {

            /* ★ Fixed: also set status='active' so user can log in */
            $update_stmt = $conn->prepare("
                UPDATE users
                SET email_verified = 1,
                    status         = 'active',
                    verify_token   = NULL
                WHERE user_id = ?
            ");

            $update_stmt->bind_param("i", $user["user_id"]);

            if ($update_stmt->execute()) {
                $success = true;
                $message = "Your email has been verified. You can now log in.";
            } else {
                $message = "Verification failed. Please try again.";
            }

            $update_stmt->close();
        }
    }

    $stmt->close();
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email | NexusBids</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>

<body>

    <div class="auth-container">

        <div class="auth-card">

            <div class="auth-logo">
                <div class="logo-icon"><i class="fa-solid fa-gavel"></i></div>
                <h1>Nexus<span>Bids</span></h1>
            </div>

            <h2><?php echo $success ? "Email Verified" : "Verification Failed"; ?></h2>

            <p class="auth-subtitle"><?php echo htmlspecialchars($message); ?></p>

            <p class="auth-footer">
                <a href="login.php">Go to Login</a>
            </p>

        </div>

    </div>

</body>

</html>