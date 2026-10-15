<?php

session_start();

require_once "../includes/csrf.php";

if (empty($_SESSION["google_pending"])) {
    header("Location: login.php");
    exit();
}

$pending = $_SESSION["google_pending"];

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Confirm Account | NexusBids</title>

    <link rel="stylesheet"
          href="../assets/css/auth.css">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

</head>

<body>

<div class="auth-container">

    <div class="auth-card">

        <div class="auth-logo">

            <div class="logo-icon">
                <i class="fa-solid fa-gavel"></i>
            </div>

            <h1>
                Nexus<span>Bids</span>
            </h1>

        </div>


        <h2>Confirm Your Account</h2>

        <p class="auth-subtitle">
            Signed in with Google as
            <strong><?php echo htmlspecialchars($pending["email"]); ?></strong>.
            No NexusBids account exists for this email yet — confirm below to create one.
        </p>


        <?php if (isset($_SESSION["error"])): ?>

            <div class="alert error">
                <?php
                echo htmlspecialchars($_SESSION["error"]);
                unset($_SESSION["error"]);
                ?>
            </div>

        <?php endif; ?>


        <form action="google-finish-signup.php" method="POST">

            <?php nb_csrf_field(); ?>

            <div class="form-group">

                <label>Full Name</label>

                <div class="input-box">
                    <i class="fa-solid fa-user"></i>
                    <input
                        type="text"
                        name="full_name"
                        value="<?php echo htmlspecialchars($pending["name"]); ?>"
                        required
                    >
                </div>

            </div>


            <div class="form-group">

                <label>Email Address</label>

                <div class="input-box">
                    <i class="fa-solid fa-envelope"></i>
                    <input
                        type="email"
                        value="<?php echo htmlspecialchars($pending["email"]); ?>"
                        disabled
                    >
                </div>

            </div>


            <div class="form-group">

                <label>Account Type</label>

                <div class="input-box">

                    <i class="fa-solid fa-user-tag"></i>

                    <select name="role">

                        <option value="user" <?php echo $pending["role"] === "user" ? "selected" : ""; ?>>
                            Buyer / Bidder
                        </option>

                        <option value="seller" <?php echo $pending["role"] === "seller" ? "selected" : ""; ?>>
                            Seller
                        </option>

                    </select>

                </div>

            </div>


            <button type="submit" class="auth-button">
                Confirm &amp; Create Account
                <i class="fa-solid fa-arrow-right"></i>
            </button>

        </form>


        <p class="auth-footer">
            Not you?
            <a href="google-cancel.php">Cancel and use a different account</a>
        </p>

    </div>

</div>

</body>

</html>
