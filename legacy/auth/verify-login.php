<?php
session_start();
require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";
require_once "../includes/email.php";
require_once "../includes/ai-security.php";   // For nb_security_record_device()

/* ============================================================
   DEV MODE FLAG
   true  = OTP screen pe dikhao (XAMPP testing ke liye)
   false = sirf email bhejo (production)
   ============================================================ */
define('NB_DEV_SHOW_OTP', false);

/* ---------- Must have pending check ---------- */
if (empty($_SESSION['pending_security_check'])) {
    header("Location: login.php");
    exit;
}

$check = $_SESSION['pending_security_check'];

/* Optional expiry: if pending check is older than 15 minutes, force re-login */
if ((time() - (int) ($check['created'] ?? 0)) > 900) {
    unset($_SESSION['pending_security_check'], $_SESSION['security_otp']);
    $_SESSION['error'] = "Verification session expired. Please log in again.";
    header("Location: login.php");
    exit;
}

/* ============================================================
   Send OTP on first load
   ============================================================ */
if (empty($_SESSION['security_otp']) || (($_SESSION['security_otp']['expires'] ?? 0) - 600) < (time() - 60)) {

    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $_SESSION['security_otp'] = [
        'code' => $otp,
        'expires' => time() + 600,      // 10 minutes
        'attempts' => 0,
    ];

    /* Fetch user email + name */
    $stmt = $conn->prepare("SELECT email, full_name FROM users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $check['user_id']);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($u && !empty($u['email'])) {

        $safe_name = htmlspecialchars($u['full_name'] ?: 'there');
        $safe_otp = htmlspecialchars($otp);

        $subject = "Your NexusBids verification code";

        /* ★ Full HTML document — bypasses nb_email_wrap() escaping */
        $body = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 16px;">
        <tr>
            <td align="center">
                <table width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,0.06);">

                    <!-- Header -->
                    <tr>
                        <td style="background:#4f46e5;padding:24px 32px;">
                            <div style="color:#ffffff;font-size:20px;font-weight:800;letter-spacing:-0.5px;">
                                NexusBids
                            </div>
                            <div style="color:#c7d2fe;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1px;margin-top:4px;">
                                Security Verification
                            </div>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 16px;color:#111827;font-size:15px;">
                                Hi <strong>' . $safe_name . '</strong>,
                            </p>

                            <p style="margin:0 0 20px;color:#374151;font-size:15px;line-height:1.6;">
                                We detected a login attempt from a <strong>new device or location</strong>.
                                Please confirm it\'s you by entering the code below:
                            </p>

                            <!-- OTP BOX -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;">
                                <tr>
                                    <td align="center" style="background:#eef2ff;border-radius:12px;padding:24px 16px;border:2px dashed #6366f1;">
                                        <div style="color:#4338ca;font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:12px;">
                                            Your Verification Code
                                        </div>
                                        <div style="color:#4338ca;font-size:40px;font-weight:900;letter-spacing:12px;font-family:\'Courier New\',Courier,monospace;line-height:1;">
                                            ' . $safe_otp . '
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:20px 0 8px;color:#6b7280;font-size:13px;">
                                This code expires in <strong>10 minutes</strong>.
                            </p>

                            <p style="margin:0;color:#6b7280;font-size:13px;line-height:1.5;">
                                If this wasn\'t you, please ignore this email and
                                <strong>change your password immediately</strong>.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background:#f9fafb;padding:20px 32px;text-align:center;border-top:1px solid #e5e7eb;">
                            <p style="margin:0;color:#9ca3af;font-size:11px;">
                                &copy; ' . date('Y') . ' NexusBids &middot; This is an automated security message.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>';

        try {
            nb_send_email($conn, (int) $check['user_id'], $u['email'], $subject, $body, 'security_otp');
        } catch (Throwable $e) {
            error_log("OTP email failed for user #{$check['user_id']}: " . $e->getMessage());
        }

        /* Always log for debugging */
        error_log("=== NEXUSBIDS OTP for user #{$check['user_id']} ({$u['email']}): {$otp} ===");
    }
}

$error = '';

/* ============================================================
   Handle OTP submission
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    nb_csrf_check();

    $entered = preg_replace('/\D/', '', $_POST['otp'] ?? '');
    $stored = $_SESSION['security_otp'] ?? null;

    if (!$stored || time() > ($stored['expires'] ?? 0)) {

        $error = 'Code expired. Please log in again.';

    } elseif (($stored['attempts'] ?? 0) >= 5) {

        $error = 'Too many attempts. Please log in again.';

    } elseif (hash_equals($stored['code'], $entered)) {

        /* ---------- SUCCESS ---------- */
        $user_id = (int) $check['user_id'];

        /* ★ Record this device so next login doesn't require OTP */
        try {
            if (function_exists('nb_security_record_device')) {
                nb_security_record_device($conn, $user_id);
            }
        } catch (Throwable $e) {
            error_log("verify-login device record failed: " . $e->getMessage());
        }

        /* Clear pending + OTP */
        unset($_SESSION['pending_security_check'], $_SESSION['security_otp']);

        /* Fetch user details */
        $stmt = $conn->prepare("SELECT full_name, role, email FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $_SESSION['error'] = 'User not found. Please log in again.';
            header("Location: login.php");
            exit;
        }

        /* Regenerate session ID for safety */
        session_regenerate_id(true);

        /* Set session */
        $_SESSION['user_id'] = $user_id;
        $_SESSION['role'] = $user['role'] ?? 'seller';
        $_SESSION['full_name'] = $user['full_name'] ?? 'User';
        $_SESSION['email'] = $user['email'] ?? '';

        /* Log success */
        try {
            if (function_exists('nb_log_login')) {
                nb_log_login($conn, $user_id, 'success');
            }
        } catch (Throwable $e) {
        }

        /* Redirect by role */
        if ($_SESSION['role'] === 'admin') {
            header("Location: ../admin/index.php");
        } elseif ($_SESSION['role'] === 'seller') {
            header("Location: ../seller/dashboard.php");
        } else {
            header("Location: ../user/dashboard.php");
        }
        exit;

    } else {

        $_SESSION['security_otp']['attempts'] = ($stored['attempts'] ?? 0) + 1;
        $error = 'Invalid code. Please try again.';
    }
}

$csrf = $_SESSION['csrf_token'] ?? '';
$dev_otp = NB_DEV_SHOW_OTP ? ($_SESSION['security_otp']['code'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="en" class="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Login · NexusBids</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', system-ui, sans-serif;
        }

        .glass {
            backdrop-filter: blur(16px) saturate(180%);
        }
    </style>
</head>

<body class="bg-slate-950 text-white min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-md">

        <div class="bg-slate-900 rounded-3xl border border-slate-800 shadow-2xl overflow-hidden">

            <!-- Header -->
            <div class="p-6 sm:p-8 text-center border-b border-slate-800">
                <div
                    class="w-16 h-16 mx-auto rounded-2xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-2xl mb-4">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <h1 class="text-2xl font-black">Verify Your Login</h1>
                <p class="text-sm text-gray-400 mt-2 max-w-xs mx-auto">
                    We detected a login attempt from a new device or location.
                    Please enter the code sent to your email.
                </p>
            </div>

            <!-- Body -->
            <div class="p-6 sm:p-8 space-y-5">

                <?php if (!empty($error)): ?>
                    <div
                        class="p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-sm flex items-start gap-2">
                        <i class="fa-solid fa-circle-exclamation mt-0.5"></i>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <?php if (NB_DEV_SHOW_OTP && $dev_otp): ?>
                    <!-- DEV ONLY: Show OTP on screen -->
                    <div class="p-4 rounded-xl bg-indigo-500/10 border border-indigo-500/30">
                        <div
                            class="text-[10px] font-bold uppercase tracking-wider text-indigo-300 mb-1 flex items-center gap-1.5">
                            <i class="fa-solid fa-code"></i> DEV MODE — OTP
                        </div>
                        <div class="text-3xl font-black tracking-[0.4em] text-indigo-200 text-center font-mono py-2">
                            <?= htmlspecialchars($dev_otp) ?>
                        </div>
                        <div class="text-[10px] text-indigo-300/70 text-center mt-1">
                            Production me ye dikhana band kar do (NB_DEV_SHOW_OTP = false)
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-4">

                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-2">
                            6-Digit Code
                        </label>
                        <input type="text" name="otp" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                            autocomplete="one-time-code" autofocus placeholder="000000"
                            class="w-full text-center text-3xl font-mono tracking-[0.5em] py-4 rounded-xl bg-slate-800 border border-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 text-white">
                    </div>

                    <button type="submit"
                        class="w-full py-3.5 rounded-xl bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 text-white font-bold text-sm shadow-lg shadow-indigo-600/30 transition-all flex items-center justify-center gap-2">
                        <i class="fa-solid fa-check"></i>
                        Verify &amp; Continue
                    </button>

                    <a href="login.php"
                        class="block text-center text-xs text-gray-400 hover:text-white py-2 transition-colors">
                        Cancel and go back to login
                    </a>

                </form>

                <!-- Footer note -->
                <div class="pt-4 border-t border-slate-800 text-center text-[11px] text-gray-500">
                    <i class="fa-solid fa-envelope mr-1"></i>
                    Didn't receive it? Check your spam folder.
                </div>

            </div>
        </div>

        <p class="text-center text-[11px] text-gray-500 mt-4">
            &copy; <?= date('Y') ?> NexusBids &middot; Security Center
        </p>

    </div>

</body>

</html>