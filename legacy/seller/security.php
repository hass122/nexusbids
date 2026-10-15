<?php

session_start();

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "seller") {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";
require_once "../includes/security.php";

date_default_timezone_set('Asia/Karachi');

$seller_id = intval($_SESSION["user_id"]);
nb_track_session($conn, $seller_id);

$error = '';
$success = '';
$new_backup_codes = [];

/* ============================================================
   POST HANDLERS
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nb_csrf_check();
    $action = $_POST['action'] ?? '';

    /* ---------- INITIATE 2FA ---------- */
    if ($action === '2fa_init') {
        try {
            $secret = nb_generate_totp_secret();
            $upd = $conn->prepare("UPDATE users SET twofa_secret = ?, twofa_enabled = 0 WHERE user_id = ?");
            $upd->bind_param("si", $secret, $seller_id);
            $upd->execute();
            $upd->close();
            $_SESSION['2fa_pending_secret'] = $secret;
            $_SESSION['success'] = "Secret generated. Scan QR and confirm.";
        } catch (Throwable $e) {
            $_SESSION['error'] = "Could not start 2FA setup.";
        }
        header("Location: security.php");
        exit();
    }

    /* ---------- CONFIRM 2FA ---------- */
    if ($action === '2fa_confirm') {
        $code = trim($_POST['code'] ?? '');
        $secret = $_SESSION['2fa_pending_secret'] ?? null;
        if (!$secret) {
            $_SESSION['error'] = "Setup session expired. Restart 2FA setup.";
        } elseif (nb_verify_totp($secret, $code)) {
            $backup_codes = nb_generate_backup_codes();
            $hashed_backup = json_encode(array_map('password_hash', $backup_codes));
            $upd = $conn->prepare("UPDATE users SET twofa_enabled = 1, twofa_backup_codes = ?, twofa_confirmed_at = NOW() WHERE user_id = ?");
            $upd->bind_param("si", $hashed_backup, $seller_id);
            $upd->execute();
            $upd->close();
            unset($_SESSION['2fa_pending_secret']);
            $_SESSION['2fa_new_codes'] = $backup_codes;
            $_SESSION['success'] = "2FA enabled successfully!";
        } else {
            $_SESSION['error'] = "Invalid code. Try again.";
        }
        header("Location: security.php");
        exit();
    }

    /* ---------- DISABLE 2FA ---------- */
    if ($action === '2fa_disable') {
        $pw = $_POST['password'] ?? '';
        $s = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
        $s->bind_param("i", $seller_id);
        $s->execute();
        $hash = $s->get_result()->fetch_assoc()['password'] ?? '';
        $s->close();
        if (!password_verify($pw, $hash)) {
            $_SESSION['error'] = "Password incorrect.";
        } else {
            $upd = $conn->prepare("UPDATE users SET twofa_enabled = 0, twofa_secret = NULL, twofa_backup_codes = NULL WHERE user_id = ?");
            $upd->bind_param("i", $seller_id);
            $upd->execute();
            $upd->close();
            $_SESSION['success'] = "2FA disabled.";
        }
        header("Location: security.php");
        exit();
    }

    /* ---------- KILL SESSION ---------- */
    if ($action === 'kill_session') {
        $sid = $_POST['session_id'] ?? '';
        if ($sid && $sid !== session_id()) {
            // verify it belongs to this user
            $chk = $conn->prepare("SELECT user_id FROM active_sessions WHERE session_id = ? LIMIT 1");
            $chk->bind_param("s", $sid);
            $chk->execute();
            $row = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($row && intval($row['user_id']) === $seller_id) {
                nb_kill_session($conn, $sid);
                $_SESSION['success'] = "Session terminated.";
            }
        }
        header("Location: security.php");
        exit();
    }

    /* ---------- CHANGE PASSWORD (with history check) ---------- */
    if ($action === 'change_password') {
        $cur = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $cfm = $_POST['confirm_password'] ?? '';

        $s = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
        $s->bind_param("i", $seller_id);
        $s->execute();
        $hash = $s->get_result()->fetch_assoc()['password'] ?? '';
        $s->close();

        $strength = nb_password_strength($new);

        if (!password_verify($cur, $hash)) {
            $_SESSION['error'] = "Current password incorrect.";
        } elseif ($strength['score'] < 60) {
            $_SESSION['error'] = "Password too weak: " . implode(', ', $strength['issues']);
        } elseif ($new !== $cfm) {
            $_SESSION['error'] = "Passwords do not match.";
        } else {
            // Check password history (last 5)
            $reused = false;
            try {
                if ($conn->query("SHOW TABLES LIKE 'password_history'")->num_rows > 0) {
                    $h = $conn->prepare("SELECT password_hash FROM password_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
                    $h->bind_param("i", $seller_id);
                    $h->execute();
                    $rows = $h->get_result()->fetch_all(MYSQLI_ASSOC);
                    $h->close();
                    foreach ($rows as $r) {
                        if (password_verify($new, $r['password_hash'])) {
                            $reused = true;
                            break;
                        }
                    }
                }
            } catch (Throwable $e) {
            }

            if ($reused) {
                $_SESSION['error'] = "You cannot reuse a recent password.";
            } else {
                $new_hash = password_hash($new, PASSWORD_DEFAULT);
                $upd = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                $upd->bind_param("si", $new_hash, $seller_id);
                $upd->execute();
                $upd->close();

                // Save to history
                try {
                    if ($conn->query("SHOW TABLES LIKE 'password_history'")->num_rows > 0) {
                        $ins = $conn->prepare("INSERT INTO password_history (user_id, password_hash) VALUES (?, ?)");
                        $ins->bind_param("is", $seller_id, $new_hash);
                        $ins->execute();
                        $ins->close();
                    }
                } catch (Throwable $e) {
                }

                // Terminate all other sessions (security best practice)
                try {
                    $del = $conn->prepare("DELETE FROM active_sessions WHERE user_id = ? AND session_id != ?");
                    $del->bind_param("is", $seller_id, session_id());
                    $del->execute();
                    $del->close();
                } catch (Throwable $e) {
                }

                $_SESSION['success'] = "Password changed. All other sessions terminated.";
            }
        }
        header("Location: security.php");
        exit();
    }
}

/* ============================================================
   LOAD DATA
   ============================================================ */
$has_2fa_cols = false;
$twofa = ['enabled' => 0, 'secret' => null, 'confirmed_at' => null];
try {
    $r = $conn->query("SHOW COLUMNS FROM users LIKE 'twofa_enabled'");
    if ($r && $r->num_rows > 0) {
        $has_2fa_cols = true;
        $s = $conn->prepare("SELECT twofa_enabled, twofa_secret, twofa_confirmed_at FROM users WHERE user_id = ?");
        $s->bind_param("i", $seller_id);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        if ($row) {
            $twofa = [
                'enabled' => intval($row['twofa_enabled']),
                'secret' => $row['twofa_secret'],
                'confirmed_at' => $row['twofa_confirmed_at'],
            ];
        }
    }
} catch (Throwable $e) {
}

$pending_secret = $_SESSION['2fa_pending_secret'] ?? null;
$new_backup_codes = $_SESSION['2fa_new_codes'] ?? [];
unset($_SESSION['2fa_new_codes']);

$user_email = '';
$s = $conn->prepare("SELECT email FROM users WHERE user_id = ?");
$s->bind_param("i", $seller_id);
$s->execute();
$user_email = $s->get_result()->fetch_assoc()['email'] ?? '';
$s->close();

/* Login activity */
$logins = [];
try {
    if ($conn->query("SHOW TABLES LIKE 'login_activity'")->num_rows > 0) {
        $stmt = $conn->prepare("SELECT * FROM login_activity WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
        $stmt->bind_param("i", $seller_id);
        $stmt->execute();
        $logins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (Throwable $e) {
}

/* Sessions */
$sessions = [];
try {
    if ($conn->query("SHOW TABLES LIKE 'active_sessions'")->num_rows > 0) {
        $stmt = $conn->prepare("SELECT * FROM active_sessions WHERE user_id = ? ORDER BY last_active DESC");
        $stmt->bind_param("i", $seller_id);
        $stmt->execute();
        $sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (Throwable $e) {
}

/* Sidebar */
$s = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ?");
$s->bind_param("i", $seller_id);
$s->execute();
$seller_info = $s->get_result()->fetch_assoc();
$s->close();
$full_name = $seller_info["full_name"] ?? "Seller";
$avatar = $seller_info["avatar"] ?? "";
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));
$unread_notifications = nb_unread_count($conn, $seller_id);

$sidebar_total = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id")->fetch_assoc()["t"] ?? 0;
$sidebar_active = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'active'")->fetch_assoc()["t"] ?? 0;
$sidebar_pending = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'pending'")->fetch_assoc()["t"] ?? 0;
$sidebar_drafts = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'draft'")->fetch_assoc()["t"] ?? 0;

function time_ago_short($dt)
{
    if (empty($dt))
        return '';
    $d = time() - strtotime($dt);
    if ($d < 60)
        return "just now";
    if ($d < 3600)
        return intdiv($d, 60) . "m ago";
    if ($d < 86400)
        return intdiv($d, 3600) . "h ago";
    if ($d < 604800)
        return intdiv($d, 86400) . "d ago";
    return date("d M Y", strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Center · NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = { darkMode: 'class', theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } } };</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        body {
            font-family: 'Inter', system-ui, sans-serif;
            transition: background-color .3s, color .3s
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 antialiased min-h-screen">
    <div class="flex min-h-screen">

        <?php
        $sidebar_active_page = 'security';
        include __DIR__ . '/../includes/seller-sidebar.php';
        ?>

        <main class="flex-1 lg:ml-64 min-w-0">

            <header
                class="sticky top-0 z-30 bg-white/80 dark:bg-slate-900/80 backdrop-blur border-b border-gray-200/80 dark:border-gray-800/80">
                <div class="h-20 px-4 sm:px-8 flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3 min-w-0">
                        <button
                            onclick="document.getElementById('sellerSidebar').classList.remove('-translate-x-full'); document.getElementById('sidebarOverlay').classList.remove('hidden')"
                            class="lg:hidden w-10 h-10 rounded-xl bg-gray-100 dark:bg-slate-800 flex items-center justify-center">
                            <i class="fa-solid fa-bars"></i>
                        </button>
                        <div class="min-w-0">
                            <div
                                class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-0.5 flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-violet-500"></span> Seller Panel
                            </div>
                            <h1 class="text-lg sm:text-xl font-black truncate">Security Center</h1>
                        </div>
                    </div>
                    <button id="themeToggle"
                        class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition-all">
                        <i class="fa-solid fa-moon dark:hidden"></i><i
                            class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                    </button>
                </div>
            </header>

            <div class="p-4 sm:p-6 lg:p-8 space-y-6 max-w-5xl mx-auto">

                <?php if (!empty($_SESSION['success'])): ?>
                    <div class="flex items-start gap-3 p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30">
                        <i class="fa-solid fa-circle-check text-emerald-600 dark:text-emerald-400 mt-1"></i>
                        <p class="text-sm text-emerald-700 dark:text-emerald-300 font-medium self-center">
                            <?= htmlspecialchars($_SESSION['success']);
                            unset($_SESSION['success']); ?>
                        </p>
                    </div>
                <?php endif; ?>
                <?php if (!empty($_SESSION['error'])): ?>
                    <div class="flex items-start gap-3 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30">
                        <i class="fa-solid fa-circle-exclamation text-rose-600 dark:text-rose-400 mt-1"></i>
                        <p class="text-sm text-rose-700 dark:text-rose-300 font-medium self-center">
                            <?= htmlspecialchars($_SESSION['error']);
                            unset($_SESSION['error']); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <!-- [NEW] Backup codes reveal (one-time) -->
                <?php if (!empty($new_backup_codes)): ?>
                    <div class="p-5 rounded-3xl bg-amber-500/10 border border-amber-500/30">
                        <div class="flex items-start gap-3 mb-4">
                            <i class="fa-solid fa-key text-amber-500 text-xl mt-1"></i>
                            <div>
                                <h3 class="font-bold text-lg">Save Your Backup Codes!</h3>
                                <p class="text-xs text-amber-800/80 dark:text-amber-300/80 mt-1">
                                    Store these somewhere safe. Each code can be used once to log in if you lose your
                                    authenticator.
                                </p>
                            </div>
                        </div>
                        <div
                            class="grid grid-cols-2 sm:grid-cols-4 gap-2 p-4 rounded-2xl bg-white dark:bg-slate-900 border border-amber-500/20 font-mono text-sm">
                            <?php foreach ($new_backup_codes as $code): ?>
                                <div class="text-center py-1.5 px-2 rounded-lg bg-gray-50 dark:bg-slate-800 font-bold">
                                    <?= htmlspecialchars($code) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <section>
                    <h2 class="text-2xl sm:text-3xl font-black tracking-tight flex items-center gap-3">
                        <span
                            class="w-11 h-11 rounded-2xl bg-gradient-to-br from-emerald-500 to-cyan-500 flex items-center justify-center text-white shadow-lg">
                            <i class="fa-solid fa-shield-halved text-base"></i>
                        </span>
                        Security Center
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Protect your account with 2FA, monitor
                        logins, and manage active sessions.</p>
                </section>

                <!-- 2FA CARD -->
                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <div
                        class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span
                                class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm">
                                <i class="fa-solid fa-mobile-screen-button"></i>
                            </span>
                            <div>
                                <h3 class="text-base font-bold">Two-Factor Authentication</h3>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Extra layer of security for
                                    your account</p>
                            </div>
                        </div>
                        <?php if ($twofa['enabled']): ?>
                            <span
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 text-[10px] font-bold uppercase tracking-wider">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Enabled
                            </span>
                        <?php else: ?>
                            <span
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-gray-500/10 text-gray-600 dark:text-gray-400 text-[10px] font-bold uppercase tracking-wider">
                                Disabled
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="p-5 sm:p-6">
                        <?php if (!$has_2fa_cols): ?>
                            <div
                                class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-sm text-amber-800 dark:text-amber-300">
                                <i class="fa-solid fa-triangle-exclamation mr-2"></i>
                                Run the migration SQL first (`seller_panel_v4_security.sql`).
                            </div>
                        <?php elseif ($twofa['enabled']): ?>
                            <!-- Already enabled -->
                            <div class="flex items-start gap-3 mb-4">
                                <div
                                    class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-500 flex items-center justify-center">
                                    <i class="fa-solid fa-check"></i>
                                </div>
                                <div>
                                    <div class="font-semibold text-sm">2FA is active on your account</div>
                                    <div class="text-xs text-gray-500 mt-0.5">
                                        Enabled <?= time_ago_short($twofa['confirmed_at']) ?>
                                    </div>
                                </div>
                            </div>

                            <form method="POST" onsubmit="return confirm('Disable 2FA? Your account will be less secure.');"
                                class="space-y-3">
                                <?php nb_csrf_field(); ?>
                                <input type="hidden" name="action" value="2fa_disable">
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">Confirm Password to
                                        Disable</label>
                                    <input type="password" name="password" required placeholder="Enter your password"
                                        class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rose-500">
                                </div>
                                <button type="submit"
                                    class="px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white text-sm font-bold transition-all">
                                    <i class="fa-solid fa-shield-slash mr-1.5 text-xs"></i> Disable 2FA
                                </button>
                            </form>

                        <?php elseif ($pending_secret): ?>
                            <!-- Setup in progress -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <h4 class="text-sm font-bold mb-3">1. Scan this QR code</h4>
                                    <div class="p-4 rounded-2xl bg-white border border-gray-200 inline-block">
                                        <div id="qrcode"></div>
                                    </div>
                                    <p class="text-[11px] text-gray-500 mt-3">
                                        Or manually enter: <code
                                            class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-slate-800 font-mono text-xs"><?= htmlspecialchars($pending_secret) ?></code>
                                    </p>
                                </div>
                                <div>
                                    <h4 class="text-sm font-bold mb-3">2. Enter the 6-digit code</h4>
                                    <form method="POST" class="space-y-3">
                                        <?php nb_csrf_field(); ?>
                                        <input type="hidden" name="action" value="2fa_confirm">
                                        <input type="text" name="code" required maxlength="6" pattern="[0-9]{6}" autofocus
                                            placeholder="123456"
                                            class="w-full px-4 py-4 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-2xl font-mono tracking-[0.5em] text-center focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                        <button type="submit"
                                            class="w-full py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-sm shadow-lg shadow-indigo-600/30 transition-all">
                                            <i class="fa-solid fa-check mr-1.5"></i> Confirm & Enable
                                        </button>
                                        <a href="security.php?cancel=1"
                                            class="block text-center text-xs text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 py-2">Cancel
                                            Setup</a>
                                    </form>
                                    <script>
                                        new QRCode(document.getElementById("qrcode"), {
                                            text: <?= json_encode(nb_totp_uri($pending_secret, $user_email)) ?>,
                                            width: 180, height: 180,
                                            colorDark: "#000", colorLight: "#fff"
                                        });
                                    </script>
                                </div>
                            </div>

                        <?php else: ?>
                            <!-- Not enabled, show CTA -->
                            <div class="text-center py-6">
                                <div
                                    class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 text-indigo-500 flex items-center justify-center text-3xl mb-5">
                                    <i class="fa-solid fa-lock"></i>
                                </div>
                                <h4 class="font-bold text-lg">Enable 2FA for maximum security</h4>
                                <p class="text-sm text-gray-500 max-w-md mx-auto mt-2">
                                    Use Google Authenticator, Authy, or any TOTP app to generate login codes.
                                </p>
                                <form method="POST" class="mt-6">
                                    <?php nb_csrf_field(); ?>
                                    <input type="hidden" name="action" value="2fa_init">
                                    <button type="submit"
                                        class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-sm shadow-lg shadow-indigo-600/30 transition-all">
                                        <i class="fa-solid fa-shield-halved text-xs"></i> Start 2FA Setup
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- PASSWORD -->
                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <div class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                        <span
                            class="w-10 h-10 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-sm">
                            <i class="fa-solid fa-key"></i>
                        </span>
                        <div>
                            <h3 class="text-base font-bold">Change Password</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Strong password recommended ·
                                Cannot reuse last 5</p>
                        </div>
                    </div>
                    <form method="POST" class="p-5 sm:p-6 space-y-4" id="pwForm">
                        <?php nb_csrf_field(); ?>
                        <input type="hidden" name="action" value="change_password">

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider mb-2">Current
                                Password</label>
                            <input type="password" name="current_password" required
                                class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider mb-2">New Password</label>
                            <input type="password" name="new_password" id="new_pw" required minlength="8"
                                class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <div class="mt-2">
                                <div class="h-1.5 rounded-full bg-gray-100 dark:bg-slate-800 overflow-hidden">
                                    <div id="pwBar" class="h-full rounded-full transition-all duration-300"
                                        style="width:0%"></div>
                                </div>
                                <div class="flex items-center justify-between mt-1.5">
                                    <span id="pwLabel" class="text-[11px] font-bold text-gray-400">Enter password</span>
                                    <span id="pwScore" class="text-[11px] tabular-nums text-gray-400">0/100</span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider mb-2">Confirm New
                                Password</label>
                            <input type="password" name="confirm_password" id="cfm_pw" required
                                class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <p id="matchMsg" class="text-[11px] mt-1.5 hidden"></p>
                        </div>

                        <button type="submit"
                            class="px-6 py-3 rounded-xl bg-violet-600 hover:bg-violet-500 text-white font-bold text-sm shadow-lg shadow-violet-600/30 transition-all">
                            <i class="fa-solid fa-key mr-1.5 text-xs"></i> Change Password
                        </button>
                    </form>
                </section>

                <!-- ACTIVE SESSIONS -->
                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <div class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                        <span
                            class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm">
                            <i class="fa-solid fa-desktop"></i>
                        </span>
                        <div>
                            <h3 class="text-base font-bold">Active Sessions</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Devices currently logged into
                                your account</p>
                        </div>
                    </div>
                    <?php if (empty($sessions)): ?>
                        <div class="p-8 text-center text-sm text-gray-500">No tracked sessions yet.</div>
                    <?php else: ?>
                        <div class="divide-y divide-gray-100 dark:divide-gray-800">
                            <?php foreach ($sessions as $se):
                                $is_current = $se['session_id'] === session_id();
                                ?>
                                <div class="p-4 flex items-center gap-3">
                                    <div
                                        class="w-9 h-9 rounded-lg <?= $is_current ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-gray-500/10 text-gray-500' ?> flex items-center justify-center text-xs">
                                        <i class="fa-solid fa-<?= $is_current ? 'check' : 'globe' ?>"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="text-xs font-bold"><?= htmlspecialchars(substr($se['user_agent'] ?? 'Unknown device', 0, 60)) ?></span>
                                            <?php if ($is_current): ?>
                                                <span
                                                    class="px-1.5 py-0.5 rounded bg-emerald-500 text-white text-[9px] font-bold uppercase tracking-wider">Current</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-[10px] text-gray-400 mt-0.5">
                                            IP <?= htmlspecialchars($se['ip_address'] ?? '—') ?>
                                            · Last active <?= time_ago_short($se['last_active'] ?? '') ?>
                                        </div>
                                    </div>
                                    <?php if (!$is_current): ?>
                                        <form method="POST" onsubmit="return confirm('Terminate this session?');">
                                            <?php nb_csrf_field(); ?>
                                            <input type="hidden" name="action" value="kill_session">
                                            <input type="hidden" name="session_id"
                                                value="<?= htmlspecialchars($se['session_id']) ?>">
                                            <button type="submit"
                                                class="px-3 py-1.5 rounded-lg bg-rose-500/10 hover:bg-rose-500 hover:text-white text-rose-600 dark:text-rose-400 text-xs font-bold transition-all">
                                                Terminate
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- LOGIN ACTIVITY -->
                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <div class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                        <span
                            class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-sm">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                        </span>
                        <div>
                            <h3 class="text-base font-bold">Recent Login Activity</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Last 20 sign-in attempts on your
                                account</p>
                        </div>
                    </div>
                    <?php if (empty($logins)): ?>
                        <div class="p-8 text-center text-sm text-gray-500">No login history yet.</div>
                    <?php else: ?>
                        <div class="divide-y divide-gray-100 dark:divide-gray-800 max-h-[400px] overflow-y-auto">
                            <?php foreach ($logins as $lg):
                                $st = strtolower($lg['status'] ?? 'success');
                                $st_styles = [
                                    'success' => ['bg-emerald-500/10', 'text-emerald-700 dark:text-emerald-400', 'Success'],
                                    'failed' => ['bg-rose-500/10', 'text-rose-700 dark:text-rose-400', 'Failed'],
                                    'locked' => ['bg-rose-500/10', 'text-rose-700 dark:text-rose-400', 'Locked'],
                                    '2fa_pending' => ['bg-amber-500/10', 'text-amber-700 dark:text-amber-400', '2FA Pending'],
                                    '2fa_failed' => ['bg-rose-500/10', 'text-rose-700 dark:text-rose-400', '2FA Failed'],
                                ];
                                $ss = $st_styles[$st] ?? $st_styles['success'];
                                ?>
                                <div class="px-5 py-3 flex items-center gap-3">
                                    <div
                                        class="w-8 h-8 rounded-lg <?= $ss[0] ?> <?= $ss[1] ?> flex items-center justify-center text-[10px]">
                                        <i
                                            class="fa-solid <?= $st === 'success' ? 'fa-check' : ($st === 'failed' || $st === 'locked' || $st === '2fa_failed' ? 'fa-xmark' : 'fa-clock') ?>"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-xs font-bold"><?= htmlspecialchars($lg['device'] ?? 'Unknown') ?> ·
                                            <?= htmlspecialchars($lg['ip_address'] ?? '—') ?></div>
                                        <div class="text-[10px] text-gray-400">
                                            <?= date('d M Y, h:i A', strtotime($lg['created_at'] ?? 'now')) ?></div>
                                    </div>
                                    <span
                                        class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider <?= $ss[0] ?> <?= $ss[1] ?>">
                                        <?= $ss[2] ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </div>

    <script>
        (function () {
            const html = document.documentElement, t = document.getElementById('themeToggle'), s = localStorage.getItem('theme');
            if (s === 'dark' || (!s && window.matchMedia('(prefers-color-scheme: dark)').matches)) html.classList.add('dark');
            t?.addEventListener('click', () => { html.classList.toggle('dark'); localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light') });

            // Password strength
            const np = document.getElementById('new_pw'), cp = document.getElementById('cfm_pw');
            const bar = document.getElementById('pwBar'), lbl = document.getElementById('pwLabel'), sc = document.getElementById('pwScore'), mm = document.getElementById('matchMsg');

            function strength(pw) {
                let s = 0;
                if (pw.length >= 8) s += 25;
                if (pw.length >= 12) s += 15;
                if (/[A-Z]/.test(pw)) s += 15;
                if (/[a-z]/.test(pw)) s += 15;
                if (/[0-9]/.test(pw)) s += 15;
                if (/[^A-Za-z0-9]/.test(pw)) s += 15;
                return Math.min(100, s);
            }
            np?.addEventListener('input', () => {
                const v = np.value;
                if (!v) { bar.style.width = '0%'; bar.style.background = ''; lbl.textContent = 'Enter password'; sc.textContent = '0/100'; return; }
                const s = strength(v);
                let color = 'bg-rose-500', label = 'Weak';
                if (s >= 80) { color = 'bg-emerald-500'; label = 'Strong'; }
                else if (s >= 60) { color = 'bg-blue-500'; label = 'Good'; }
                else if (s >= 40) { color = 'bg-amber-500'; label = 'Fair'; }
                bar.className = 'h-full rounded-full transition-all duration-300 ' + color;
                bar.style.width = s + '%';
                lbl.textContent = label; sc.textContent = s + '/100';
            });
            cp?.addEventListener('input', () => {
                if (!cp.value) { mm.classList.add('hidden'); return; }
                const ok = np.value === cp.value;
                mm.classList.remove('hidden');
                mm.className = 'text-[11px] mt-1.5 flex items-center gap-1.5 ' + (ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400');
                mm.innerHTML = ok ? '<i class="fa-solid fa-circle-check text-[9px]"></i>Passwords match' : '<i class="fa-solid fa-circle-xmark text-[9px]"></i>Passwords do not match';
            });
        })();
    </script>
<?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>