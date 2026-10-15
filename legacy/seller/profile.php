<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";

$user_id = intval($_SESSION["user_id"]);
$is_seller = ($_SESSION["role"] ?? "") === "seller";

if (!$is_seller) {
    header("Location: ../user/dashboard.php");
    exit();
}

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    nb_csrf_check();
    $action = $_POST["action"] ?? "";

    /* ---------- UPDATE PROFILE ---------- */
    if ($action === "update_profile") {
        $full_name = trim($_POST["full_name"] ?? "");
        $phone = trim($_POST["phone"] ?? "");

        if ($full_name === "") {
            $error = "Full name is required.";
        } else {
            $avatar_clause = "";
            $avatar_file = null;

            if (isset($_FILES["avatar"]) && $_FILES["avatar"]["error"] === UPLOAD_ERR_OK) {
                $allowed = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
                $mime = mime_content_type($_FILES["avatar"]["tmp_name"]);

                if (array_key_exists($mime, $allowed) && getimagesize($_FILES["avatar"]["tmp_name"])) {
                    $dir = "../uploads/avatars/";
                    if (!is_dir($dir))
                        mkdir($dir, 0777, true);
                    $avatar_file = "avatar_" . $user_id . "_" . time() . "." . $allowed[$mime];
                    move_uploaded_file($_FILES["avatar"]["tmp_name"], $dir . $avatar_file);
                    $avatar_clause = ", avatar = ?";
                }
            }

            $sql = "UPDATE users SET full_name = ?, phone = ? $avatar_clause WHERE user_id = ?";
            $stmt = $conn->prepare($sql);
            if ($avatar_file)
                $stmt->bind_param("sssi", $full_name, $phone, $avatar_file, $user_id);
            else
                $stmt->bind_param("ssi", $full_name, $phone, $user_id);

            if ($stmt->execute()) {
                $_SESSION["full_name"] = $full_name;
                $success = "Profile updated successfully.";
            } else
                $error = "Something went wrong. Please try again.";
        }
    }

    /* ---------- UPDATE PAYOUT INFO ---------- */
    if ($action === "update_payout") {
        $method = trim($_POST["payout_method"] ?? '');
        $title = trim($_POST["payout_account_title"] ?? '');
        $number = trim($_POST["payout_account_number"] ?? '');
        $bank = trim($_POST["payout_bank_name"] ?? '');

        if (!in_array($method, ['bank', 'jazzcash', 'easypaisa'], true)) {
            $error = "Please select a valid payout method.";
        } elseif ($title === "" || $number === "") {
            $error = "Account title and number are required.";
        } elseif ($method === 'bank' && $bank === "") {
            $error = "Bank name is required for bank transfers.";
        } else {
            $bank_val = $method === 'bank' ? $bank : null;
            $upd = $conn->prepare("
                UPDATE users
                SET payout_method = ?, payout_account_title = ?, payout_account_number = ?, payout_bank_name = ?
                WHERE user_id = ?
            ");
            $upd->bind_param("ssssi", $method, $title, $number, $bank_val, $user_id);
            $success = $upd->execute() ? "Payout details updated." : "Could not save payout details.";
            $upd->close();
        }
    }

    /* ---------- CHANGE PASSWORD ---------- */
    if ($action === "change_password") {
        $cur = $_POST["current_password"] ?? "";
        $new = $_POST["new_password"] ?? "";
        $conf = $_POST["confirm_password"] ?? "";

        $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user_pw = $stmt->get_result()->fetch_assoc();

        if (!password_verify($cur, $user_pw["password"]))
            $error = "Current password is incorrect.";
        elseif (strlen($new) < 6)
            $error = "New password must be at least 6 characters.";
        elseif ($new !== $conf)
            $error = "New passwords do not match.";
        else {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $upd->bind_param("si", $hashed, $user_id);
            $upd->execute();
            $success = "Password changed successfully.";
        }
    }
}

/* ---------- FETCH USER ---------- */
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$full_name = $user["full_name"] ?? "Seller";
$email = $user["email"] ?? "";
$phone = $user["phone"] ?? "";
$avatar = $user["avatar"] ?? "";
$is_verified = !empty($user["email_verified"]);
$member_since = $user["created_at"] ?? date("Y-m-d H:i:s");
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));

$payout_method = $user["payout_method"] ?? '';
$payout_account_title = $user["payout_account_title"] ?? '';
$payout_account_number = $user["payout_account_number"] ?? '';
$payout_bank_name = $user["payout_bank_name"] ?? '';

$unread_notifications = nb_unread_count($conn, $user_id);

/* Sidebar counts */
$sidebar_total = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $user_id")->fetch_assoc()["t"] ?? 0;
$sidebar_active = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $user_id AND auction_status = 'active'")->fetch_assoc()["t"] ?? 0;
$sidebar_pending = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $user_id AND auction_status = 'pending'")->fetch_assoc()["t"] ?? 0;
$sidebar_drafts = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $user_id AND auction_status = 'draft'")->fetch_assoc()["t"] ?? 0;

/* KYC status — SELLER */
$seller_kyc_status = $user['seller_kyc_status'] ?? 'unverified';
$kyc_styles = [
    'unverified' => ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'label' => 'Not Verified'],
    'pending' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'label' => 'Pending Review'],
    'approved' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Verified'],
    'rejected' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Rejected'],
];
$ks = $kyc_styles[$seller_kyc_status] ?? $kyc_styles['unverified'];
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = {darkMode: 'class', theme: {extend: {fontFamily: {sans: ['Inter', 'system-ui', 'sans-serif']}, animation: {'fade-up': 'fadeUp .6s ease-out forwards'}, keyframes: {fadeUp: {'0%': {opacity: '0', transform: 'translateY(16px)'}, '100%': {opacity: '1', transform: 'translateY(0)'}}}}};</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', system-ui, sans-serif;
            transition: background-color .3s, color .3s;
        }

        .glass {
            backdrop-filter: blur(16px) saturate(180%);
        }

        .avatar-upload {
            position: relative;
            display: inline-block;
            cursor: pointer;
        }

        .avatar-upload input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }

        .avatar-upload:hover .avatar-overlay {
            opacity: 1;
        }

        .avatar-overlay {
            opacity: 0;
            transition: opacity .2s ease;
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 antialiased min-h-screen">
    <div class="flex min-h-screen">

        <?php
        $sidebar_active_page = 'profile';
        include __DIR__ . '/../includes/seller-sidebar.php';
        ?>

        <main class="flex-1 lg:ml-64 min-w-0">

            <header
                class="sticky top-0 z-30 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
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
                            <h1 class="text-lg sm:text-xl font-black truncate">My Profile</h1>
                        </div>
                    </div>
                    <button id="themeToggle"
                        class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition-all">
                        <i class="fa-solid fa-moon dark:hidden"></i><i
                            class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                    </button>
                </div>
            </header>

            <div class="p-4 sm:p-6 lg:p-8 space-y-6 max-w-6xl mx-auto">

                <section class="animate-fade-up">
                    <nav class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mb-3">
                        <a href="dashboard.php" class="hover:text-indigo-600">Dashboard</a>
                        <i class="fa-solid fa-chevron-right text-[8px]"></i>
                        <span class="text-gray-900 dark:text-white font-semibold">Profile</span>
                    </nav>
                    <h2 class="text-2xl sm:text-3xl font-black tracking-tight flex items-center gap-3">
                        <span
                            class="w-11 h-11 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-500 flex items-center justify-center text-white shadow-lg">
                            <i class="fa-solid fa-user text-base"></i>
                        </span>
                        Account Settings
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Manage your personal info, payout details,
                        and password.</p>
                </section>

                <?php if ($error): ?>
                    <div
                        class="flex items-start gap-3 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 animate-fade-up">
                        <i class="fa-solid fa-circle-exclamation text-rose-600 dark:text-rose-400 mt-1"></i>
                        <p class="text-sm text-rose-700 dark:text-rose-300 font-medium self-center">
                            <?= htmlspecialchars($error) ?>
                        </p>
                    </div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div
                        class="flex items-start gap-3 p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 animate-fade-up">
                        <i class="fa-solid fa-circle-check text-emerald-600 dark:text-emerald-400 mt-1"></i>
                        <p class="text-sm text-emerald-700 dark:text-emerald-300 font-medium self-center">
                            <?= htmlspecialchars($success) ?>
                        </p>
                    </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

                    <!-- LEFT: Profile Card -->
                    <div class="lg:col-span-4 space-y-6 animate-fade-up">
                        <div
                            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                            <div
                                class="h-24 bg-gradient-to-r from-indigo-600 via-violet-600 to-purple-600 relative overflow-hidden">
                                <div
                                    class="absolute -right-8 -top-8 w-32 h-32 bg-white/10 rounded-full blur-2xl pointer-events-none">
                                </div>
                            </div>

                            <div class="px-6 pb-6 -mt-12 relative">
                                <form method="POST" enctype="multipart/form-data" id="avatarForm" class="inline-block">
                                    <?php nb_csrf_field(); ?>
                                    <input type="hidden" name="action" value="update_profile">
                                    <input type="hidden" name="full_name" value="<?= htmlspecialchars($full_name) ?>">
                                    <input type="hidden" name="phone" value="<?= htmlspecialchars($phone) ?>">
                                    <label class="avatar-upload block relative">
                                        <?php if ($avatar): ?>
                                            <img src="../uploads/avatars/<?= htmlspecialchars($avatar) ?>" alt=""
                                                class="w-24 h-24 rounded-2xl object-cover ring-4 ring-white dark:ring-slate-900 shadow-xl">
                                        <?php else: ?>
                                            <div
                                                class="w-24 h-24 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-black text-2xl flex items-center justify-center ring-4 ring-white dark:ring-slate-900 shadow-xl">
                                                <?= htmlspecialchars($initials) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div
                                            class="avatar-overlay absolute inset-0 rounded-2xl bg-black/60 flex items-center justify-center text-white">
                                            <div class="text-center">
                                                <i class="fa-solid fa-camera text-lg"></i>
                                                <p class="text-[9px] font-bold mt-1 uppercase tracking-wider">Change</p>
                                            </div>
                                        </div>
                                        <input type="file" name="avatar" accept=".jpg,.jpeg,.png,.webp"
                                            onchange="document.getElementById('avatarForm').submit();">
                                    </label>
                                </form>

                                <div class="mt-4 flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h2 class="text-xl font-black truncate"><?= htmlspecialchars($full_name) ?></h2>
                                        <p class="text-sm text-gray-500 dark:text-gray-400 truncate">
                                            <?= htmlspecialchars($email) ?>
                                        </p>
                                    </div>
                                    <span
                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-indigo-500/10 text-indigo-700 dark:text-indigo-400 border border-indigo-500/20 text-[10px] font-bold uppercase tracking-wider shrink-0">
                                        <i class="fa-solid fa-store text-[9px]"></i> Seller
                                    </span>
                                </div>

                                <div
                                    class="mt-5 pt-5 border-t border-gray-100 dark:border-gray-800 grid grid-cols-2 gap-4">
                                    <div>
                                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1">
                                            Status</div>
                                        <div
                                            class="flex items-center gap-1.5 text-xs font-bold <?= $is_verified ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' ?>">
                                            <span
                                                class="w-1.5 h-1.5 rounded-full <?= $is_verified ? 'bg-emerald-500' : 'bg-amber-500' ?>"></span>
                                            <?= $is_verified ? 'Verified' : 'Unverified' ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1">
                                            Member Since</div>
                                        <div class="text-xs font-bold text-gray-700 dark:text-gray-300">
                                            <?= date("M Y", strtotime($member_since)) ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4 flex items-center gap-2 text-[10px] text-gray-400 font-mono">
                                    <i class="fa-solid fa-id-badge"></i> User ID:
                                    #<?= str_pad($user_id, 6, '0', STR_PAD_LEFT) ?>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Links -->
                        <div
                            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
                            <h3 class="text-sm font-bold mb-3 flex items-center gap-2"><i
                                    class="fa-solid fa-link text-indigo-500"></i>Quick Links</h3>
                            <div class="space-y-1">
                                <a class="flex items-center justify-between p-3 rounded-xl hover:bg-gray-50 dark:hover:bg-slate-800/50 group"
                                    href="dashboard.php">
                                    <span class="flex items-center gap-3 text-sm font-medium">
                                        <span
                                            class="w-8 h-8 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs"><i
                                                class="fa-solid fa-chart-line"></i></span>Dashboard
                                    </span>
                                    <i
                                        class="fa-solid fa-chevron-right text-xs text-gray-400 group-hover:translate-x-0.5 transition-transform"></i>
                                </a>
                                <a class="flex items-center justify-between p-3 rounded-xl hover:bg-gray-50 dark:hover:bg-slate-800/50 group"
                                    href="payouts.php">
                                    <span class="flex items-center gap-3 text-sm font-medium">
                                        <span
                                            class="w-8 h-8 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xs"><i
                                                class="fa-solid fa-wallet"></i></span>Payouts
                                    </span>
                                    <i
                                        class="fa-solid fa-chevron-right text-xs text-gray-400 group-hover:translate-x-0.5 transition-transform"></i>
                                </a>
                                <a class="flex items-center justify-between p-3 rounded-xl hover:bg-gray-50 dark:hover:bg-slate-800/50 group"
                                    href="reviews.php">
                                    <span class="flex items-center gap-3 text-sm font-medium">
                                        <span
                                            class="w-8 h-8 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-xs"><i
                                                class="fa-solid fa-star"></i></span>Reviews
                                    </span>
                                    <i
                                        class="fa-solid fa-chevron-right text-xs text-gray-400 group-hover:translate-x-0.5 transition-transform"></i>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT: Forms -->
                    <div class="lg:col-span-8 space-y-6 animate-fade-up">

                        <!-- Personal Info -->
                        <div
                            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                            <div
                                class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                <span
                                    class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm"><i
                                        class="fa-solid fa-user-pen"></i></span>
                                <div>
                                    <h2 class="text-base font-bold">Profile Information</h2>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Update name and contact
                                        details</p>
                                </div>
                            </div>
                            <form method="POST" enctype="multipart/form-data" class="p-5 sm:p-6 space-y-5">
                                <?php nb_csrf_field(); ?>
                                <input type="hidden" name="action" value="update_profile">

                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider mb-2"
                                        for="full_name">Full Name <span class="text-rose-500">*</span></label>
                                    <div class="relative">
                                        <i
                                            class="fa-solid fa-user absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                        <input type="text" name="full_name" id="full_name"
                                            value="<?= htmlspecialchars($full_name) ?>" required
                                            class="w-full pl-11 pr-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">Email</label>
                                    <div class="relative">
                                        <i
                                            class="fa-solid fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                        <input type="email" value="<?= htmlspecialchars($email) ?>" disabled
                                            class="w-full pl-11 pr-16 py-3 bg-gray-100 dark:bg-slate-800/50 border border-gray-200 dark:border-gray-700 rounded-xl text-sm text-gray-500 cursor-not-allowed">
                                        <span
                                            class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold uppercase tracking-wider text-gray-400 bg-gray-200 dark:bg-slate-700 px-2 py-0.5 rounded flex items-center gap-1">
                                            <i class="fa-solid fa-lock text-[8px]"></i>Locked
                                        </span>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider mb-2"
                                        for="phone">Phone</label>
                                    <div class="relative">
                                        <i
                                            class="fa-solid fa-phone absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                        <input type="text" name="phone" id="phone"
                                            value="<?= htmlspecialchars($phone) ?>" placeholder="+92 300 1234567"
                                            class="w-full pl-11 pr-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    </div>
                                </div>

                                <div class="flex justify-end">
                                    <button type="submit"
                                        class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold shadow-lg shadow-indigo-600/30 transition-all hover:-translate-y-0.5">
                                        <i class="fa-solid fa-floppy-disk text-xs"></i> Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- KYC / Verification (SELLER) -->
                        <div id="kyc"
                            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                            <div
                                class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
                                <div class="flex items-center gap-3">
                                    <span
                                        class="w-10 h-10 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-sm"><i
                                            class="fa-solid fa-shield-halved"></i></span>
                                    <div>
                                        <h2 class="text-base font-bold">Seller Verification (KYC)</h2>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Verify identity &
                                            bank to receive payouts</p>
                                    </div>
                                </div>
                                <span
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full <?= $ks['bg'] . ' ' . $ks['text'] ?> text-[10px] font-bold uppercase tracking-wider">
                                    <?= $ks['label'] ?>
                                </span>
                            </div>
                            <div class="p-5 sm:p-6">
                                <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
                                    <?php
                                    $items = [
                                        'email' => ['Email', !empty($user['email_verified'])],
                                        'phone' => ['Phone', !empty($user['phone_verified'])],
                                        'identity' => ['Identity', $seller_kyc_status === 'approved'],
                                        'bank' => ['Bank', !empty($user['bank_verified'])],
                                        'address' => ['Address', !empty($user['address_verified'])],
                                    ];
                                    foreach ($items as $k => [$label, $ok]):
                                        ?>
                                        <div
                                            class="text-center p-3 rounded-xl border <?= $ok ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-gray-200 dark:border-gray-800' ?>">
                                            <div
                                                class="w-8 h-8 rounded-lg mx-auto mb-2 flex items-center justify-center <?= $ok ? 'bg-emerald-500 text-white' : 'bg-gray-200 dark:bg-slate-800 text-gray-400' ?>">
                                                <i class="fa-solid <?= $ok ? 'fa-check' : 'fa-clock' ?> text-xs"></i>
                                            </div>
                                            <div
                                                class="text-[11px] font-bold <?= $ok ? 'text-emerald-700 dark:text-emerald-400' : 'text-gray-500' ?>">
                                                <?= $label ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <?php if ($seller_kyc_status === 'unverified' || $seller_kyc_status === 'rejected'): ?>
                                    <div class="mt-5 pt-5 border-t border-gray-100 dark:border-gray-800">
                                        <a href="kyc.php"
                                            class="w-full py-3 rounded-xl bg-violet-600 hover:bg-violet-500 text-white font-bold text-sm shadow-lg shadow-violet-600/30 flex items-center justify-center gap-2 transition-all">
                                            <i class="fa-solid fa-id-card"></i>
                                            <?= $seller_kyc_status === 'rejected' ? 'Retry KYC Verification' : 'Start Seller KYC' ?>
                                        </a>
                                        <p class="text-[11px] text-gray-500 text-center mt-2">
                                            Takes about 3-5 minutes · Required for payouts
                                        </p>
                                    </div>
                                <?php elseif ($seller_kyc_status === 'pending'): ?>
                                    <div
                                        class="mt-5 p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-start gap-2">
                                        <i class="fa-solid fa-clock text-amber-500 mt-0.5"></i>
                                        <p class="text-xs text-amber-800 dark:text-amber-300">Your verification is under
                                            review. This usually takes 1–2 business days.</p>
                                    </div>
                                <?php elseif ($seller_kyc_status === 'approved'): ?>
                                    <div
                                        class="mt-5 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-start gap-2">
                                        <i class="fa-solid fa-circle-check text-emerald-500 mt-0.5"></i>
                                        <p class="text-xs text-emerald-800 dark:text-emerald-300">Your seller account is
                                            verified. You can list items and receive payouts.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Payout Info -->
                        <div
                            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                            <div
                                class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                <span
                                    class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm"><i
                                        class="fa-solid fa-wallet"></i></span>
                                <div>
                                    <h2 class="text-base font-bold">Payout Details</h2>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Where should we send your
                                        earnings?</p>
                                </div>
                            </div>
                            <form method="POST" class="p-5 sm:p-6 space-y-5" id="payoutForm">
                                <?php nb_csrf_field(); ?>
                                <input type="hidden" name="action" value="update_payout">

                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">Preferred
                                        Method <span class="text-rose-500">*</span></label>
                                    <div class="grid grid-cols-3 gap-2">
                                        <?php foreach (['bank' => 'Bank Transfer', 'jazzcash' => 'JazzCash', 'easypaisa' => 'EasyPaisa'] as $k => $v): ?>
                                            <label class="cursor-pointer">
                                                <input type="radio" name="payout_method" value="<?= $k ?>"
                                                    class="peer sr-only payout-radio" <?= $payout_method === $k ? 'checked' : '' ?> required>
                                                <div
                                                    class="text-center p-3 rounded-xl border-2 border-gray-200 dark:border-gray-700 peer-checked:border-indigo-500 peer-checked:bg-indigo-500/10 transition-all">
                                                    <i
                                                        class="fa-solid <?= $k === 'bank' ? 'fa-building-columns text-indigo-500' : 'fa-mobile-screen text-emerald-500' ?> text-sm mb-1 block"></i>
                                                    <span
                                                        class="text-[10px] font-bold uppercase tracking-wider"><?= $v ?></span>
                                                </div>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div id="bankNameWrap" class="hidden">
                                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">Bank Name <span
                                            class="text-rose-500">*</span></label>
                                    <input type="text" name="payout_bank_name" id="bankNameInput"
                                        value="<?= htmlspecialchars($payout_bank_name) ?>"
                                        placeholder="e.g. HBL, Meezan, UBL"
                                        class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Account
                                            Title <span class="text-rose-500">*</span></label>
                                        <input type="text" name="payout_account_title"
                                            value="<?= htmlspecialchars($payout_account_title ?: $full_name) ?>"
                                            required
                                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Account # /
                                            IBAN <span class="text-rose-500">*</span></label>
                                        <input type="text" name="payout_account_number"
                                            value="<?= htmlspecialchars($payout_account_number) ?>" required
                                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono">
                                    </div>
                                </div>

                                <div class="flex items-center justify-between gap-3 pt-1">
                                    <p class="text-[11px] text-gray-400 flex items-center gap-1.5">
                                        <i class="fa-solid fa-circle-info text-[9px]"></i>Used automatically in payout
                                        requests.
                                    </p>
                                    <button type="submit"
                                        class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-bold shadow-lg shadow-emerald-600/30 transition-all hover:-translate-y-0.5">
                                        <i class="fa-solid fa-floppy-disk text-xs"></i> Save Payout Info
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Password -->
                        <div
                            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                            <div
                                class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                <span
                                    class="w-10 h-10 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-sm"><i
                                        class="fa-solid fa-key"></i></span>
                                <div>
                                    <h2 class="text-base font-bold">Change Password</h2>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Keep your account secure
                                    </p>
                                </div>
                            </div>
                            <form method="POST" class="p-5 sm:p-6 space-y-5" id="passwordForm">
                                <?php nb_csrf_field(); ?>
                                <input type="hidden" name="action" value="change_password">

                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">Current
                                        Password</label>
                                    <div class="relative">
                                        <i
                                            class="fa-solid fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                        <input type="password" name="current_password" required
                                            class="w-full pl-11 pr-12 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">New
                                        Password</label>
                                    <div class="relative">
                                        <i
                                            class="fa-solid fa-key absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                        <input type="password" name="new_password" id="new_password" required
                                            minlength="6"
                                            class="w-full pl-11 pr-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">Confirm New
                                        Password</label>
                                    <div class="relative">
                                        <i
                                            class="fa-solid fa-key absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                        <input type="password" name="confirm_password" id="confirm_password" required
                                            class="w-full pl-11 pr-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    </div>
                                    <p id="matchMessage" class="text-[11px] mt-1.5 hidden"></p>
                                </div>

                                <div class="flex justify-end">
                                    <button type="submit"
                                        class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-violet-600 hover:bg-violet-500 text-white text-sm font-bold shadow-lg shadow-violet-600/30 transition-all hover:-translate-y-0.5">
                                        <i class="fa-solid fa-key text-xs"></i> Change Password
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="mt-8 border-t border-gray-200 dark:border-gray-800 px-4 sm:px-8 py-6">
                <div
                    class="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
                    <p>© <?= date("Y") ?> NexusBids Seller Panel.</p>
                </div>
            </footer>
        </main>
    </div>

    <script>
    (function () {
        const html = document.documentElement, t = document.getElementById('themeToggle'), s = localStorage.getItem('theme');
        if (s === 'dark' || (!s && window.matchMedia('(prefers-color-scheme: dark)').matches)) html.classList.add('dark');
        t?.addEventListener('click', () => {html.classList.toggle('dark'); localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light')});

        const bankWrap = document.getElementById('bankNameWrap'), bankInput = document.getElementById('bankNameInput');
        function toggleBank() {
            const v = document.querySelector('.payout-radio:checked')?.value;
            if (v === 'bank') {bankWrap.classList.remove('hidden'); bankInput.required = true;}
            else {bankWrap.classList.add('hidden'); bankInput.required = false;}
        }
        document.querySelectorAll('.payout-radio').forEach(r => r.addEventListener('change', toggleBank));
        toggleBank();

        const np = document.getElementById('new_password'), cp = document.getElementById('confirm_password'), mm = document.getElementById('matchMessage');
        function check() {
            if (!cp.value) {mm.classList.add('hidden'); return;}
            const m = np.value === cp.value;
            mm.classList.remove('hidden');
            mm.className = 'text-[11px] mt-1.5 flex items-center gap-1.5 ' + (m ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400');
            mm.innerHTML = m ? '<i class="fa-solid fa-circle-check text-[9px]"></i>Passwords match' : '<i class="fa-solid fa-circle-xmark text-[9px]"></i>Passwords do not match';
        }
        cp?.addEventListener('input', check);
    })();
    </script>
    <?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>