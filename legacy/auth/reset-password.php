<?php

session_start();

require_once "../config/database.php";
require_once "../includes/csrf.php";

$token = trim($_GET["token"] ?? $_POST["token"] ?? "");
$error = "";
$success = "";
$token_valid = false;

if (empty($token)) {

    $error = "Missing reset token.";

} else {

    $stmt = $conn->prepare("
        SELECT user_id, reset_expires
        FROM users
        WHERE reset_token = ?
    ");

    $stmt->bind_param("s", $token);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows === 0) {

        $error = "This reset link is invalid or has already been used.";

    } elseif (strtotime($result->fetch_row()[1] ?? "now") < time()) {

        $error = "This reset link has expired. Please request a new one.";

    } else {

        $token_valid = true;
    }
}


if ($token_valid && $_SERVER["REQUEST_METHOD"] === "POST") {

    nb_csrf_check();

    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if (strlen($password) < 6) {

        $error = "Password must contain at least 6 characters.";

    } elseif ($password !== $confirm_password) {

        $error = "Passwords do not match.";

    } else {

        $stmt = $conn->prepare("
            SELECT user_id
            FROM users
            WHERE reset_token = ? AND reset_expires > NOW()
        ");

        $stmt->bind_param("s", $token);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows === 1) {

            $user = $result->fetch_assoc();
            $hashed = password_hash($password, PASSWORD_DEFAULT);

            $update_stmt = $conn->prepare("
                UPDATE users
                SET password = ?, reset_token = NULL, reset_expires = NULL,
                    failed_attempts = 0, locked_until = NULL
                WHERE user_id = ?
            ");

            $update_stmt->bind_param("si", $hashed, $user["user_id"]);
            $update_stmt->execute();

            $success = "Your password has been reset. You can now log in.";
            $token_valid = false; // hide the form after success

        } else {

            $error = "This reset link is invalid or has expired.";
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | NexusBids</title>
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

            <h2>Reset Password</h2>

            <?php if ($error): ?>
                <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if ($token_valid): ?>

                <form method="POST">

                    <?php nb_csrf_field(); ?>
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                    <div class="form-group">
                        <label>New Password</label>
                        <div class="input-box">
                            <i class="fa-solid fa-lock"></i>
                            <input type="password" name="password" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <div class="input-box">
                            <i class="fa-solid fa-lock"></i>
                            <input type="password" name="confirm_password" required>
                        </div>
                    </div>

                    <button type="submit" class="auth-button">
                        Reset Password
                        <i class="fa-solid fa-arrow-right"></i>
                    </button>

                </form>

            <?php endif; ?>

            <p class="auth-footer">
                <a href="login.php">Back to Login</a>
            </p>

        </div>

    </div>

</body>

</html>
