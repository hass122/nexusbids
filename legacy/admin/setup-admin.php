<?php

/*
|--------------------------------------------------------------------------
| ONE-TIME ADMIN SETUP
|--------------------------------------------------------------------------
| Use this page once to create your first admin account, then DELETE
| THIS FILE. It is not linked from anywhere in the site and has no
| login check of its own — anyone who finds the URL could use it to
| create an admin account, so remove it as soon as you're done.
|--------------------------------------------------------------------------
*/

session_start();

require_once "../config/database.php";


/*
|--------------------------------------------------------------------------
| Setup Key
|--------------------------------------------------------------------------
| Simple shared-secret so a random visitor can't just stumble onto this
| URL and create an admin account. Change this before using the page.
|--------------------------------------------------------------------------
*/

define("SETUP_KEY", "change-this-key-123");

$error = "";
$success = "";


/*
|--------------------------------------------------------------------------
| Handle Form Submission
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $setup_key = $_POST["setup_key"] ?? "";
    $full_name = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";


    if ($setup_key !== SETUP_KEY) {

        $error = "Invalid setup key.";

    } elseif (
        empty($full_name) ||
        empty($email) ||
        empty($password) ||
        empty($confirm_password)
    ) {

        $error = "Please fill in all required fields.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Please enter a valid email address.";

    } elseif (strlen($password) < 6) {

        $error = "Password must contain at least 6 characters.";

    } elseif ($password !== $confirm_password) {

        $error = "Passwords do not match.";

    } else {

        $check_stmt = $conn->prepare("
            SELECT user_id
            FROM users
            WHERE email = ?
        ");

        $check_stmt->bind_param("s", $email);
        $check_stmt->execute();

        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {

            $error = "This email is already registered.";

        } else {

            $hashed_password = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $role = "admin";
            $status = "active";

            $insert_stmt = $conn->prepare("
                INSERT INTO users
                (
                    full_name,
                    email,
                    phone,
                    password,
                    role,
                    status
                )
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $insert_stmt->bind_param(
                "ssssss",
                $full_name,
                $email,
                $phone,
                $hashed_password,
                $role,
                $status
            );

            if ($insert_stmt->execute()) {

                $success = "Admin account created! You can now log in at "
                    . "auth/login.php with this email and password. "
                    . "Delete this file (admin/setup-admin.php) now.";

            } else {

                $error = "Could not create account: " . $conn->error;

            }

        }

    }

}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Create Admin Account | NexusBids</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }

        .setup-box {
            background: #1e293b;
            padding: 32px;
            border-radius: 12px;
            width: 100%;
            max-width: 420px;
        }

        .setup-box h1 {
            font-size: 20px;
            margin-bottom: 4px;
        }

        .setup-box p.subtitle {
            color: #94a3b8;
            font-size: 13px;
            margin-top: 0;
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-size: 13px;
            margin-bottom: 6px;
            margin-top: 14px;
        }

        input {
            width: 100%;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #334155;
            background: #0f172a;
            color: #e2e8f0;
            box-sizing: border-box;
            font-size: 14px;
        }

        button {
            width: 100%;
            margin-top: 22px;
            padding: 12px;
            border: none;
            border-radius: 8px;
            background: #6366f1;
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }

        .alert {
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 16px;
        }

        .alert-error {
            background: #7f1d1d;
            color: #fecaca;
        }

        .alert-success {
            background: #14532d;
            color: #bbf7d0;
        }

        .warning {
            font-size: 12px;
            color: #fbbf24;
            margin-top: 18px;
            line-height: 1.5;
        }
    </style>

</head>

<body>

    <div class="setup-box">

        <h1><i class="fa-solid fa-user-shield"></i> Create Admin Account</h1>
        <p class="subtitle">One-time setup page for NexusBids</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if (!$success): ?>

            <form method="POST">

                <label>Setup Key</label>
                <input type="password" name="setup_key" required>

                <label>Full Name</label>
                <input type="text" name="full_name" required>

                <label>Email</label>
                <input type="email" name="email" required>

                <label>Phone (optional)</label>
                <input type="text" name="phone">

                <label>Password</label>
                <input type="password" name="password" required>

                <label>Confirm Password</label>
                <input type="password" name="confirm_password" required>

                <button type="submit">Create Admin Account</button>

            </form>

        <?php endif; ?>

        <p class="warning">
            <i class="fa-solid fa-triangle-exclamation"></i>
            Change the SETUP_KEY constant at the top of this file before
            using it, and delete admin/setup-admin.php from the server
            once your admin account is created.
        </p>

    </div>

</body>

</html>
