<?php

session_start();

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/rate-limit.php";
require_once "../includes/ai-security.php";    // ★ AI Account Takeover Detection


if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: login.php");
    exit();
}

nb_csrf_check();


/* ---------- IP-based throttle (before anything) ---------- */
if (!nb_throttle("login_" . ($_SERVER["REMOTE_ADDR"] ?? "unknown"), 10, 60)) {
    $_SESSION["error"] = "Too many login attempts. Please wait a minute and try again.";
    header("Location: login.php");
    exit();
}


$email    = trim($_POST["email"] ?? "");
$password = $_POST["password"] ?? "";

if (empty($email) || empty($password)) {
    $_SESSION["error"] = "Please enter your email and password.";
    header("Location: login.php");
    exit();
}


/* ---------- Fetch user ---------- */
$sql = "SELECT * FROM users WHERE email = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {

    $user = $result->fetch_assoc();


    /* ---------- Account status ---------- */
    if ($user["status"] !== "active") {
        $_SESSION["error"] = "Your account is currently inactive or blocked.";
        header("Location: login.php");
        exit();
    }


    /* ---------- Account lockout ---------- */
    if (nb_is_locked($user)) {
        $_SESSION["error"] = "Too many failed attempts. This account is temporarily locked — try again in a few minutes.";
        header("Location: login.php");
        exit();
    }


    /* ---------- Password check ---------- */
    if (password_verify($password, $user["password"])) {

        /* Reset failed-login counter */
        nb_reset_failed_login($conn, $user["user_id"]);

        /* Regenerate session ID (prevent fixation) */
        session_regenerate_id(true);


        /* ============================================================
           ★ AI ACCOUNT TAKEOVER DETECTION
           Runs AFTER password is verified, BEFORE session is finalized
           
           ★ Admin accounts SKIP this check (no OTP for admin)
           ★ Seller & Buyer accounts get the full AI check
           ============================================================ */

        $is_admin = (($user["role"] ?? '') === 'admin');

        if (!$is_admin) {
            try {
                $sec    = nb_security_handle_login($conn, (int) $user["user_id"]);
                $action = $sec["action"] ?? "allow";

                /* ---------- BLOCK: deny login entirely ---------- */
                if ($action === "block") {

                    if (function_exists("nb_log_login")) {
                        nb_log_login($conn, (int) $user["user_id"], "blocked_ai");
                    }

                    session_unset();
                    session_destroy();

                    /* Start a fresh session to carry the error message */
                    session_start();
                    session_regenerate_id(true);

                    $_SESSION["error"] =
                        "Suspicious activity detected on your account. "
                      . "For your security, this login was blocked. "
                      . "Please reset your password or contact support if this was you.";

                    header("Location: login.php");
                    exit();
                }

                /* ---------- VERIFY: require OTP ---------- */
                if ($action === "verify") {

                    if (function_exists("nb_log_login")) {
                        nb_log_login($conn, (int) $user["user_id"], "verify_required");
                    }

                    $_SESSION["pending_security_check"] = [
                        "user_id"  => (int) $user["user_id"],
                        "alert_id" => (int) ($sec["alert_id"] ?? 0),
                        "score"    => (int) ($sec["score"] ?? 0),
                        "reason"   => (string) ($sec["reason"] ?? ""),
                        "created"  => time(),
                    ];

                    header("Location: verify-login.php");
                    exit();
                }

                /* ---------- ALLOW: continue normal flow ---------- */
                if ($action === "allow") {
                    if (function_exists("nb_log_login")) {
                        nb_log_login($conn, (int) $user["user_id"], "success");
                    }
                }

            } catch (Throwable $e) {
                /* FAIL-OPEN: if security check crashes, do NOT block login */
                error_log("AI security check failed at login: " . $e->getMessage());

                if (function_exists("nb_log_login")) {
                    nb_log_login($conn, (int) $user["user_id"], "success");
                }
            }
        } else {
            /* ---------- ADMIN: skip AI check, just log the login ---------- */
            if (function_exists("nb_log_login")) {
                nb_log_login($conn, (int) $user["user_id"], "success");
            }
        }
        /* ============================================================
           ★ END AI SECURITY
           ============================================================ */


        /* ---------- Set session ---------- */
        $_SESSION["user_id"]   = $user["user_id"];
        $_SESSION["full_name"] = $user["full_name"];
        $_SESSION["email"]     = $user["email"];
        $_SESSION["role"]      = $user["role"];


        /* ---------- Redirect by role ---------- */
        if ($user["role"] === "admin") {
            header("Location: ../admin/index.php");
        } elseif ($user["role"] === "seller") {
            header("Location: ../seller/dashboard.php");
        } else {
            header("Location: ../user/dashboard.php");
        }

        exit();
    }

    /* ---------- Wrong password ---------- */
    nb_register_failed_login($conn, $user["user_id"], $user["failed_attempts"] ?? 0);
}


$_SESSION["error"] = "Invalid email or password.";
header("Location: login.php");
exit();