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
$back_dashboard = $is_seller ? "../seller/dashboard.php" : "dashboard.php";

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    nb_csrf_check();

    $action = $_POST["action"] ?? "";

    if ($action === "update_profile") {

        $full_name = trim($_POST["full_name"] ?? "");
        $phone = trim($_POST["phone"] ?? "");

        if (empty($full_name)) {
            $error = "Full name is required.";
        } else {
            $avatar_clause = "";
            $avatar_file = null;

            if (isset($_FILES["avatar"]) && $_FILES["avatar"]["error"] === 0) {
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

            if ($avatar_file) {
                $stmt->bind_param("sssi", $full_name, $phone, $avatar_file, $user_id);
            } else {
                $stmt->bind_param("ssi", $full_name, $phone, $user_id);
            }

            if ($stmt->execute()) {
                $_SESSION["full_name"] = $full_name;
                $success = "Profile updated successfully.";
            } else {
                $error = "Something went wrong. Please try again.";
            }
        }

    } elseif ($action === "change_password") {

        $current_password = $_POST["current_password"] ?? "";
        $new_password = $_POST["new_password"] ?? "";
        $confirm_password = $_POST["confirm_password"] ?? "";

        $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user_pw = $stmt->get_result()->fetch_assoc();

        if (!password_verify($current_password, $user_pw["password"])) {
            $error = "Current password is incorrect.";
        } elseif (strlen($new_password) < 6) {
            $error = "New password must be at least 6 characters.";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match.";
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $update->bind_param("si", $hashed, $user_id);
            $update->execute();
            $success = "Password changed successfully.";
        }
    }
}

$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$full_name = $user["full_name"] ?? "User";
$email = $user["email"] ?? "";
$phone = $user["phone"] ?? "";
$avatar = $user["avatar"] ?? "";
$role = $user["role"] ?? "user";
$is_verified = !empty($user["email_verified"]);
$member_since = $user["created_at"] ?? date("Y-m-d H:i:s");
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));

$unread_notifications = nb_unread_count($conn, $user_id);

/* Password strength helper */
function password_strength($pwd)
{
    $score = 0;
    $len = strlen($pwd);
    if ($len >= 6)
        $score++;
    if ($len >= 10)
        $score++;
    if (preg_match('/[A-Z]/', $pwd))
        $score++;
    if (preg_match('/[0-9]/', $pwd))
        $score++;
    if (preg_match('/[^A-Za-z0-9]/', $pwd))
        $score++;
    $score = min(4, $score);
    $labels = ['Very weak', 'Weak', 'Fair', 'Strong', 'Excellent'];
    $colors = ['bg-rose-500', 'bg-rose-500', 'bg-amber-500', 'bg-emerald-500', 'bg-emerald-600'];
    return ['score' => $score, 'label' => $labels[$score], 'color' => $colors[$score]];
}

?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <meta name="description" content="Manage your NexusBids profile, contact details, and account security.">

    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            50: '#eef2ff', 100: '#e0e7ff', 200: '#c7d2fe', 300: '#a5b4fc',
                            400: '#818cf8', 500: '#6366f1', 600: '#4f46e5', 700: '#4338ca',
                            800: '#3730a3', 900: '#312e81',
                        }
                    },
                    animation: {
                        'fade-up': 'fadeUp 0.6s ease-out forwards',
                        'fade-in': 'fadeIn 0.4s ease-out forwards',
                        'pulse-soft': 'pulseSoft 2s ease-in-out infinite',
                    },
                    keyframes: {
                        fadeUp: {'0%': {opacity: '0', transform: 'translateY(16px)'}, '100%': {opacity: '1', transform: 'translateY(0)'}},
                        fadeIn: {'0%': {opacity: '0'}, '100%': {opacity: '1'}},
                        pulseSoft: {'0%, 100%': {opacity: '1'}, '50%': {opacity: '0.5'}},
                    }
                }
            }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <style>
        html {
            scroll-behavior: smooth;
        }

        body {
            transition: background-color .3s ease, color .3s ease;
            font-feature-settings: 'cv02', 'cv03', 'cv04', 'cv11';
        }

        .glass {
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
        }

        .gradient-text {
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
        }

        .noise-overlay::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 1;
        }

        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .dark ::-webkit-scrollbar-thumb {
            background: #334155;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .dark ::-webkit-scrollbar-thumb:hover {
            background: #475569;
        }

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                animation-duration: .01ms !important;
                transition-duration: .01ms !important;
            }
        }

        /* File upload drop zone */
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

        /* Password strength bars */
        .strength-bar {
            transition: background-color .3s ease;
        }
    </style>
</head>

<body
    class="bg-gray-50 text-gray-900 dark:bg-slate-950 dark:text-gray-100 font-sans antialiased selection:bg-indigo-500 selection:text-white min-h-screen flex flex-col">

    <!-- Top Utility Bar -->
    <div class="bg-gray-950 text-gray-400 text-xs py-2.5 px-4 sm:px-8 border-b border-gray-800/50 relative z-40">
        <div class="max-w-[1600px] mx-auto flex flex-col sm:flex-row justify-between items-center gap-2">
            <div class="flex items-center space-x-6">
                <span class="flex items-center gap-2"><i class="fa-solid fa-location-dot text-indigo-400"></i>
                    Pakistan</span>
                <span class="hidden sm:flex items-center gap-2"><i class="fa-solid fa-phone text-indigo-400"></i> +92
                    300 1234567</span>
            </div>
            <div class="flex items-center space-x-6">
                <a href="../about.php" class="hover:text-white transition-colors duration-200"><i
                        class="fa-regular fa-circle-question text-indigo-400"></i> Help Center</a>
                <a href="../about.php#why-nexusbids" class="hover:text-white transition-colors duration-200"><i
                        class="fa-solid fa-shield-halved text-indigo-400"></i> Secure Bidding</a>
            </div>
        </div>
    </div>

    <!-- Navbar -->
    <header
        class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
        <div class="max-w-[1600px] mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
            <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight text-gray-900 dark:text-white shrink-0 group"
                href="../index.php">
                <span
                    class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30 group-hover:shadow-indigo-500/50 group-hover:scale-105 transition-all duration-300">
                    <i class="fa-solid fa-gavel text-lg"></i>
                </span>
                Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
            </a>

            <nav class="hidden lg:flex items-center gap-7 text-sm font-medium">
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
                    href="../index.php">Home</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
                    href="../auctions.php">Auctions</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
                    href="../categories.php">Categories</a>
                <a class="relative text-indigo-600 dark:text-indigo-400 font-semibold after:absolute after:-bottom-1 after:left-0 after:w-full after:h-0.5 after:bg-indigo-600 dark:after:bg-indigo-400 after:rounded-full"
                    href="<?php echo htmlspecialchars($back_dashboard); ?>">Dashboard</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
                    href="../about.php">About</a>
            </nav>

            <div class="flex items-center gap-2 sm:gap-3">
                <button aria-label="Toggle Theme"
                    class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-all duration-200 hover:scale-105"
                    id="themeToggle">
                    <i class="fa-solid fa-moon dark:hidden"></i><i
                        class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                </button>

                <a href="../notifications.php"
                    class="relative w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-all duration-200 hover:scale-105"
                    title="Notifications">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($unread_notifications > 0): ?><span
                            class="absolute top-1.5 right-1.5 w-2.5 h-2.5 rounded-full bg-rose-500 border-2 border-white dark:border-gray-800 animate-pulse-soft"></span><?php endif; ?>
                </a>

                <div class="flex items-center gap-3 pl-3 border-l border-gray-200 dark:border-gray-800">
                    <div class="relative">
                        <?php if (!empty($avatar)): ?>
                            <img src="../uploads/avatars/<?php echo htmlspecialchars($avatar); ?>" alt="Avatar"
                                class="w-10 h-10 rounded-full object-cover ring-2 ring-white dark:ring-slate-900 shadow-lg">
                        <?php else: ?>
                            <div
                                class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center shadow-lg shadow-indigo-500/30 ring-2 ring-white dark:ring-slate-900">
                                <?php echo htmlspecialchars($initials); ?>
                            </div>
                        <?php endif; ?>
                        <span
                            class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full <?php echo $is_verified ? 'bg-emerald-500' : 'bg-amber-500'; ?> border-2 border-white dark:border-slate-900"></span>
                    </div>
                    <div class="hidden sm:block text-left">
                        <div class="text-sm font-bold text-gray-900 dark:text-white leading-tight truncate max-w-32">
                            <?php echo htmlspecialchars($full_name); ?>
                        </div>
                        <div
                            class="text-[11px] <?php echo $is_verified ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'; ?> font-medium flex items-center gap-1">
                            <i
                                class="fa-solid <?php echo $is_verified ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-[8px]"></i>
                            <?php echo $is_verified ? 'Verified' : 'Unverified'; ?>
                        </div>
                    </div>
                </div>

                <a class="hidden sm:inline-flex px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition-all duration-200 hover:shadow-lg hover:shadow-indigo-600/30"
                    href="../auth/logout.php">
                    <i class="fa-solid fa-right-from-bracket mr-1.5"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <main class="flex-grow max-w-[1600px] mx-auto w-full px-4 sm:px-8 py-8 space-y-8">

        <!-- Page Header -->
        <section class="animate-fade-up">
            <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                <div>
                    <nav class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mb-3">
                        <a href="<?php echo htmlspecialchars($back_dashboard); ?>"
                            class="hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">Dashboard</a>
                        <i class="fa-solid fa-chevron-right text-[8px]"></i>
                        <span class="text-gray-900 dark:text-white font-semibold">Profile</span>
                    </nav>
                    <h1
                        class="text-3xl sm:text-4xl font-black tracking-tight text-gray-900 dark:text-white flex items-center gap-3">
                        <span
                            class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-500 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30">
                            <i class="fa-solid fa-user text-lg"></i>
                        </span>
                        My Profile
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Manage your account information, contact
                        details, and password.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="<?php echo htmlspecialchars($back_dashboard); ?>"
                        class="px-5 py-2.5 rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 hover:border-indigo-500 dark:hover:border-indigo-500 text-gray-700 dark:text-gray-200 text-sm font-semibold transition-all duration-200 flex items-center gap-2">
                        <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </section>

        <!-- Flash Messages -->
        <?php if (!empty($error)): ?>
            <div
                class="flex items-start gap-3 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 dark:border-rose-500/20 animate-fade-up">
                <div
                    class="w-9 h-9 rounded-lg bg-rose-500/20 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-circle-exclamation"></i>
                </div>
                <p class="text-sm text-rose-700 dark:text-rose-300 font-medium self-center">
                    <?php echo htmlspecialchars($error); ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div
                class="flex items-start gap-3 p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 dark:border-emerald-500/20 animate-fade-up">
                <div
                    class="w-9 h-9 rounded-lg bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <p class="text-sm text-emerald-700 dark:text-emerald-300 font-medium self-center">
                    <?php echo htmlspecialchars($success); ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if (!$is_verified): ?>
            <div
                class="flex items-start gap-4 p-5 rounded-2xl bg-amber-500/10 border border-amber-500/30 dark:border-amber-500/20 animate-fade-up">
                <div
                    class="w-11 h-11 rounded-xl bg-amber-500/20 text-amber-600 dark:text-amber-400 flex items-center justify-center text-lg shrink-0">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="font-bold text-amber-900 dark:text-amber-300 text-sm">Verify your email address</h3>
                    <p class="text-xs text-amber-800/80 dark:text-amber-300/80 mt-1 leading-relaxed">We sent a verification
                        link to <strong><?php echo htmlspecialchars($email); ?></strong>. Verify your email to unlock
                        bidding and full account features.</p>
                </div>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

            <!-- Left Column: Profile Card + Info -->
            <div class="lg:col-span-5 space-y-6 animate-fade-up" style="animation-delay: 0.05s">

                <!-- Profile Card -->
                <div
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <!-- Cover gradient -->
                    <div
                        class="h-24 bg-gradient-to-r from-indigo-600 via-violet-600 to-purple-600 relative overflow-hidden noise-overlay">
                        <div class="absolute inset-0 opacity-30"
                            style="background-image: linear-gradient(rgba(255,255,255,.15) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.15) 1px, transparent 1px); background-size: 30px 30px;">
                        </div>
                        <div
                            class="absolute -right-8 -top-8 w-32 h-32 bg-white/10 rounded-full blur-2xl pointer-events-none">
                        </div>
                    </div>

                    <div class="px-6 pb-6 -mt-12 relative">
                        <!-- Avatar with upload -->
                        <form method="POST" enctype="multipart/form-data" id="avatarForm" class="inline-block">
                            <?php nb_csrf_field(); ?>
                            <input type="hidden" name="action" value="update_profile">
                            <input type="hidden" name="full_name" value="<?php echo htmlspecialchars($full_name); ?>">
                            <input type="hidden" name="phone" value="<?php echo htmlspecialchars($phone); ?>">
                            <label class="avatar-upload block relative">
                                <?php if (!empty($avatar)): ?>
                                    <img src="../uploads/avatars/<?php echo htmlspecialchars($avatar); ?>" alt="Avatar"
                                        class="w-24 h-24 rounded-2xl object-cover ring-4 ring-white dark:ring-slate-900 shadow-xl">
                                <?php else: ?>
                                    <div
                                        class="w-24 h-24 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-black text-2xl flex items-center justify-center ring-4 ring-white dark:ring-slate-900 shadow-xl">
                                        <?php echo htmlspecialchars($initials); ?>
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
                                <h2 class="text-xl font-black text-gray-900 dark:text-white truncate">
                                    <?php echo htmlspecialchars($full_name); ?>
                                </h2>
                                <p class="text-sm text-gray-500 dark:text-gray-400 truncate">
                                    <?php echo htmlspecialchars($email); ?>
                                </p>
                            </div>
                            <span
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full <?php echo $is_seller ? 'bg-indigo-500/10 text-indigo-700 dark:text-indigo-400 border-indigo-500/20' : 'bg-blue-500/10 text-blue-700 dark:text-blue-400 border-blue-500/20'; ?> text-[10px] font-bold uppercase tracking-wider border shrink-0">
                                <i class="fa-solid <?php echo $is_seller ? 'fa-store' : 'fa-user'; ?> text-[9px]"></i>
                                <?php echo $is_seller ? 'Seller' : 'Bidder'; ?>
                            </span>
                        </div>

                        <!-- Meta info -->
                        <div class="mt-5 pt-5 border-t border-gray-100 dark:border-gray-800 grid grid-cols-2 gap-4">
                            <div>
                                <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1">Status
                                </div>
                                <div
                                    class="flex items-center gap-1.5 text-xs font-bold <?php echo $is_verified ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'; ?>">
                                    <span
                                        class="w-1.5 h-1.5 rounded-full <?php echo $is_verified ? 'bg-emerald-500' : 'bg-amber-500'; ?> <?php echo !$is_verified ? 'animate-pulse' : ''; ?>"></span>
                                    <?php echo $is_verified ? 'Verified' : 'Unverified'; ?>
                                </div>
                            </div>
                            <div>
                                <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1">Member
                                    Since</div>
                                <div class="text-xs font-bold text-gray-700 dark:text-gray-300">
                                    <?php echo date("M Y", strtotime($member_since)); ?>
                                </div>
                            </div>
                        </div>

                        <!-- User ID -->
                        <div class="mt-4 flex items-center gap-2 text-[10px] text-gray-400 font-mono">
                            <i class="fa-solid fa-id-badge"></i>
                            User ID: #<?php echo str_pad($user_id, 6, '0', STR_PAD_LEFT); ?>
                        </div>
                    </div>
                </div>

                <!-- Quick Links -->
                <div
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-3 flex items-center gap-2">
                        <i class="fa-solid fa-link text-indigo-500"></i>
                        Quick Links
                    </h3>
                    <div class="space-y-1">
                        <a class="flex items-center justify-between p-3 rounded-xl hover:bg-gray-50 dark:hover:bg-slate-800/50 transition-colors duration-200 group"
                            href="<?php echo htmlspecialchars($back_dashboard); ?>">
                            <span class="flex items-center gap-3 text-sm font-medium text-gray-700 dark:text-gray-300">
                                <span
                                    class="w-8 h-8 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs">
                                    <i class="fa-solid fa-chart-line"></i>
                                </span>
                                Dashboard
                            </span>
                            <i
                                class="fa-solid fa-chevron-right text-xs text-gray-400 group-hover:translate-x-0.5 transition-transform duration-200"></i>
                        </a>
                        <a class="flex items-center justify-between p-3 rounded-xl hover:bg-gray-50 dark:hover:bg-slate-800/50 transition-colors duration-200 group"
                            href="../notifications.php">
                            <span class="flex items-center gap-3 text-sm font-medium text-gray-700 dark:text-gray-300">
                                <span
                                    class="w-8 h-8 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-xs">
                                    <i class="fa-solid fa-bell"></i>
                                </span>
                                Notifications
                            </span>
                            <?php if ($unread_notifications > 0): ?>
                                <span
                                    class="text-[10px] font-black text-white bg-rose-500 px-2 py-0.5 rounded-full tabular-nums"><?php echo $unread_notifications; ?></span>
                            <?php else: ?>
                                <i
                                    class="fa-solid fa-chevron-right text-xs text-gray-400 group-hover:translate-x-0.5 transition-transform duration-200"></i>
                            <?php endif; ?>
                        </a>
                        <a class="flex items-center justify-between p-3 rounded-xl hover:bg-gray-50 dark:hover:bg-slate-800/50 transition-colors duration-200 group"
                            href="../auth/logout.php">
                            <span class="flex items-center gap-3 text-sm font-medium text-gray-700 dark:text-gray-300">
                                <span
                                    class="w-8 h-8 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center text-xs">
                                    <i class="fa-solid fa-right-from-bracket"></i>
                                </span>
                                Logout
                            </span>
                            <i
                                class="fa-solid fa-chevron-right text-xs text-gray-400 group-hover:translate-x-0.5 transition-transform duration-200"></i>
                        </a>
                    </div>
                </div>

                <!-- Security Note -->
                <div
                    class="relative rounded-3xl bg-slate-950 text-white p-5 overflow-hidden shadow-xl border border-gray-800 noise-overlay">
                    <div
                        class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-indigo-900/40 via-slate-950 to-slate-950">
                    </div>
                    <div
                        class="absolute -right-12 -top-12 w-48 h-48 bg-emerald-600/20 rounded-full blur-3xl pointer-events-none">
                    </div>

                    <div class="relative">
                        <div class="flex items-center gap-2 mb-3">
                            <i class="fa-solid fa-shield-halved text-emerald-400"></i>
                            <h4 class="text-sm font-bold">Account Security</h4>
                        </div>
                        <p class="text-[11px] text-gray-300 leading-relaxed">Use a strong, unique password. We recommend
                            enabling email verification for the best protection.</p>
                    </div>
                </div>
            </div>

            <!-- Right Column: Forms -->
            <div class="lg:col-span-7 space-y-6 animate-fade-up" style="animation-delay: 0.1s">

                <!-- Profile Information Form -->
                <div
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <div
                        class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span
                                class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm">
                                <i class="fa-solid fa-user-pen"></i>
                            </span>
                            <div>
                                <h2 class="text-base font-bold text-gray-900 dark:text-white">Profile Information</h2>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Update your name and contact
                                    details</p>
                            </div>
                        </div>
                    </div>

                    <form method="POST" enctype="multipart/form-data" class="p-5 sm:p-6 space-y-5">
                        <?php nb_csrf_field(); ?>
                        <input type="hidden" name="action" value="update_profile">

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div class="sm:col-span-2">
                                <label
                                    class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-2"
                                    for="full_name">
                                    Full Name <span class="text-rose-500">*</span>
                                </label>
                                <div class="relative">
                                    <i
                                        class="fa-solid fa-user absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                    <input type="text" name="full_name" id="full_name"
                                        value="<?php echo htmlspecialchars($full_name); ?>" required
                                        class="w-full pl-11 pr-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all duration-200 placeholder:text-gray-400">
                                </div>
                            </div>

                            <div class="sm:col-span-2">
                                <label
                                    class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-2"
                                    for="email">
                                    Email Address
                                </label>
                                <div class="relative">
                                    <i
                                        class="fa-solid fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                    <input type="email" id="email" value="<?php echo htmlspecialchars($email); ?>"
                                        disabled
                                        class="w-full pl-11 pr-16 py-3 bg-gray-100 dark:bg-slate-800/50 border border-gray-200 dark:border-gray-700 rounded-xl text-sm text-gray-500 dark:text-gray-400 cursor-not-allowed">
                                    <span
                                        class="absolute right-3 top-1/2 -translate-y-1/2 inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-gray-400 bg-gray-200 dark:bg-slate-700 px-2 py-0.5 rounded">
                                        <i class="fa-solid fa-lock text-[8px]"></i> Locked
                                    </span>
                                </div>
                                <p class="text-[11px] text-gray-400 mt-1.5 flex items-center gap-1.5">
                                    <i class="fa-solid fa-circle-info text-[9px]"></i>
                                    Email cannot be changed. Contact support if you need to update it.
                                </p>
                            </div>

                            <div class="sm:col-span-2">
                                <label
                                    class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-2"
                                    for="phone">
                                    Phone Number
                                </label>
                                <div class="relative">
                                    <i
                                        class="fa-solid fa-phone absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                    <input type="text" name="phone" id="phone"
                                        value="<?php echo htmlspecialchars($phone); ?>"
                                        class="w-full pl-11 pr-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all duration-200 placeholder:text-gray-400"
                                        placeholder="+92 300 1234567">
                                </div>
                            </div>

                            <div class="sm:col-span-2">
                                <label
                                    class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-2">
                                    Profile Picture
                                </label>
                                <label
                                    class="cursor-pointer group flex items-center gap-4 p-4 rounded-xl border-2 border-dashed border-gray-200 dark:border-gray-700 hover:border-indigo-500 dark:hover:border-indigo-500 bg-gray-50 dark:bg-slate-800/50 transition-all duration-200">
                                    <div
                                        class="w-12 h-12 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-lg shrink-0 group-hover:scale-110 transition-transform duration-300">
                                        <i class="fa-solid fa-cloud-arrow-up"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-bold text-gray-900 dark:text-white">Click to upload</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">JPG, PNG, or WEBP ·
                                            Max 5MB</p>
                                    </div>
                                    <input type="file" name="avatar" accept=".jpg,.jpeg,.png,.webp" class="sr-only"
                                        id="avatarInput">
                                </label>
                                <p class="text-[11px] text-gray-400 mt-1.5 flex items-center gap-1.5">
                                    <i class="fa-solid fa-circle-info text-[9px]"></i>
                                    Leave empty to keep your current picture.
                                </p>
                            </div>
                        </div>

                        <div class="pt-2 flex items-center justify-end gap-2">
                            <button type="submit"
                                class="group inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold shadow-lg shadow-indigo-600/30 hover:shadow-indigo-500/40 transition-all duration-300 hover:-translate-y-0.5">
                                <i class="fa-solid fa-floppy-disk text-xs"></i>
                                Save Changes
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Change Password Form -->
                <div
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <div
                        class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span
                                class="w-10 h-10 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-sm">
                                <i class="fa-solid fa-key"></i>
                            </span>
                            <div>
                                <h2 class="text-base font-bold text-gray-900 dark:text-white">Change Password</h2>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Keep your account secure</p>
                            </div>
                        </div>
                        <span
                            class="hidden sm:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20 text-[10px] font-bold uppercase tracking-wider">
                            <i class="fa-solid fa-shield-halved text-[9px]"></i>
                            Encrypted
                        </span>
                    </div>

                    <form method="POST" class="p-5 sm:p-6 space-y-5" id="passwordForm">
                        <?php nb_csrf_field(); ?>
                        <input type="hidden" name="action" value="change_password">

                        <div>
                            <label
                                class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-2"
                                for="current_password">
                                Current Password
                            </label>
                            <div class="relative">
                                <i
                                    class="fa-solid fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                <input type="password" name="current_password" id="current_password" required
                                    class="w-full pl-11 pr-12 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all duration-200">
                                <button type="button"
                                    class="password-toggle absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
                                    data-target="current_password">
                                    <i class="fa-regular fa-eye text-sm"></i>
                                </button>
                            </div>
                        </div>

                        <div>
                            <label
                                class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-2"
                                for="new_password">
                                New Password
                            </label>
                            <div class="relative">
                                <i
                                    class="fa-solid fa-key absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                <input type="password" name="new_password" id="new_password" required minlength="6"
                                    class="w-full pl-11 pr-12 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all duration-200">
                                <button type="button"
                                    class="password-toggle absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
                                    data-target="new_password">
                                    <i class="fa-regular fa-eye text-sm"></i>
                                </button>
                            </div>
                            <!-- Strength meter -->
                            <div class="mt-3">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Password
                                        Strength</span>
                                    <span id="strengthLabel" class="text-[10px] font-bold text-gray-400">—</span>
                                </div>
                                <div class="grid grid-cols-4 gap-1.5">
                                    <div class="h-1.5 rounded-full bg-gray-200 dark:bg-slate-700 strength-bar"></div>
                                    <div class="h-1.5 rounded-full bg-gray-200 dark:bg-slate-700 strength-bar"></div>
                                    <div class="h-1.5 rounded-full bg-gray-200 dark:bg-slate-700 strength-bar"></div>
                                    <div class="h-1.5 rounded-full bg-gray-200 dark:bg-slate-700 strength-bar"></div>
                                </div>
                                <ul class="mt-3 space-y-1.5 text-[11px] text-gray-500 dark:text-gray-400">
                                    <li class="strength-req" data-req="length"><i
                                            class="fa-solid fa-circle text-[5px] text-gray-300 mr-1.5"></i>At least 6
                                        characters</li>
                                    <li class="strength-req" data-req="upper"><i
                                            class="fa-solid fa-circle text-[5px] text-gray-300 mr-1.5"></i>Contains
                                        uppercase letter</li>
                                    <li class="strength-req" data-req="number"><i
                                            class="fa-solid fa-circle text-[5px] text-gray-300 mr-1.5"></i>Contains
                                        number</li>
                                    <li class="strength-req" data-req="special"><i
                                            class="fa-solid fa-circle text-[5px] text-gray-300 mr-1.5"></i>Contains
                                        special character</li>
                                </ul>
                            </div>
                        </div>

                        <div>
                            <label
                                class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-2"
                                for="confirm_password">
                                Confirm New Password
                            </label>
                            <div class="relative">
                                <i
                                    class="fa-solid fa-key absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                <input type="password" name="confirm_password" id="confirm_password" required
                                    class="w-full pl-11 pr-12 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all duration-200">
                                <button type="button"
                                    class="password-toggle absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
                                    data-target="confirm_password">
                                    <i class="fa-regular fa-eye text-sm"></i>
                                </button>
                            </div>
                            <p id="matchMessage" class="text-[11px] mt-1.5 hidden flex items-center gap-1.5">
                                <i class="text-[9px]"></i><span></span>
                            </p>
                        </div>

                        <div class="pt-2 flex items-center justify-between gap-3">
                            <p class="text-[11px] text-gray-400 flex items-center gap-1.5">
                                <i class="fa-solid fa-circle-info text-[9px]"></i>
                                You'll stay logged in after changing your password.
                            </p>
                            <button type="submit"
                                class="group inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-violet-600 hover:bg-violet-500 text-white text-sm font-bold shadow-lg shadow-violet-600/30 hover:shadow-violet-500/40 transition-all duration-300 hover:-translate-y-0.5">
                                <i class="fa-solid fa-key text-xs"></i>
                                Change Password
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Danger Zone -->
                <div
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-rose-200 dark:border-rose-900/50 shadow-sm overflow-hidden">
                    <div class="p-5 sm:p-6 border-b border-rose-100 dark:border-rose-900/30 flex items-center gap-3">
                        <span
                            class="w-10 h-10 rounded-xl bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center text-sm">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                        </span>
                        <div>
                            <h2 class="text-base font-bold text-rose-700 dark:text-rose-400">Danger Zone</h2>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Irreversible account actions</p>
                        </div>
                    </div>
                    <div class="p-5 sm:p-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                        <div class="flex-1 min-w-0">
                            <h3 class="text-sm font-bold text-gray-900 dark:text-white">Delete Account</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">Permanently delete
                                your account and all associated data. This action cannot be undone.</p>
                        </div>
                        <a href="../about.php#contact"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-900/50 hover:bg-rose-100 dark:hover:bg-rose-950/50 text-rose-700 dark:text-rose-400 text-sm font-bold transition-all duration-200 whitespace-nowrap">
                            <i class="fa-solid fa-headset text-xs"></i>
                            Contact Support
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-slate-950 text-gray-400 text-xs border-t border-gray-900 mt-8">
        <div class="max-w-[1600px] mx-auto px-4 sm:px-8 py-16 grid grid-cols-1 md:grid-cols-5 gap-10">
            <div class="md:col-span-2 space-y-5">
                <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight text-white" href="../index.php">
                    <div
                        class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30">
                        <i class="fa-solid fa-gavel text-lg"></i>
                    </div>
                    Nexus<span class="text-indigo-400">Bids</span>
                </a>
                <p class="text-gray-400 text-xs leading-relaxed max-w-sm">Pakistan's modern online auction marketplace.
                    Discover, bid and win products you love.</p>
                <div class="flex items-center space-x-3 pt-2">
                    <a aria-label="Facebook"
                        class="w-10 h-10 rounded-xl bg-slate-900 border border-gray-800 flex items-center justify-center text-gray-300 hover:text-white hover:border-indigo-500/50 hover:bg-indigo-500/10 transition-all duration-200"
                        href="#"><i class="fa-brands fa-facebook-f"></i></a>
                    <a aria-label="Instagram"
                        class="w-10 h-10 rounded-xl bg-slate-900 border border-gray-800 flex items-center justify-center text-gray-300 hover:text-white hover:border-indigo-500/50 hover:bg-indigo-500/10 transition-all duration-200"
                        href="#"><i class="fa-brands fa-instagram"></i></a>
                    <a aria-label="X"
                        class="w-10 h-10 rounded-xl bg-slate-900 border border-gray-800 flex items-center justify-center text-gray-300 hover:text-white hover:border-indigo-500/50 hover:bg-indigo-500/10 transition-all duration-200"
                        href="#"><i class="fa-brands fa-x-twitter"></i></a>
                    <a aria-label="LinkedIn"
                        class="w-10 h-10 rounded-xl bg-slate-900 border border-gray-800 flex items-center justify-center text-gray-300 hover:text-white hover:border-indigo-500/50 hover:bg-indigo-500/10 transition-all duration-200"
                        href="#"><i class="fa-brands fa-linkedin-in"></i></a>
                </div>
            </div>
            <div class="space-y-4">
                <h4 class="text-sm font-bold text-white uppercase tracking-wider">Marketplace</h4>
                <ul class="space-y-2.5">
                    <li><a class="hover:text-white transition-colors duration-200" href="../auctions.php">Browse
                            Auctions</a></li>
                    <li><a class="hover:text-white transition-colors duration-200" href="../auctions.php">Live
                            Auctions</a></li>
                    <li><a class="hover:text-white transition-colors duration-200"
                            href="../categories.php">Categories</a></li>
                    <li><a class="hover:text-white transition-colors duration-200" href="../index.php#how-it-works">How
                            It Works</a></li>
                </ul>
            </div>
            <div class="space-y-4">
                <h4 class="text-sm font-bold text-white uppercase tracking-wider">Account</h4>
                <ul class="space-y-2.5">
                    <li><a class="hover:text-white transition-colors duration-200"
                            href="<?php echo htmlspecialchars($back_dashboard); ?>">My Dashboard</a></li>
                    <li><a class="hover:text-white transition-colors duration-200" href="profile.php">My Profile</a>
                    </li>
                    <li><a class="hover:text-white transition-colors duration-200"
                            href="../notifications.php">Notifications</a></li>
                    <li><a class="hover:text-white transition-colors duration-200" href="../auth/logout.php">Logout</a>
                    </li>
                </ul>
            </div>
            <div class="space-y-4">
                <h4 class="text-sm font-bold text-white uppercase tracking-wider">Support</h4>
                <ul class="space-y-2.5">
                    <li><a class="hover:text-white transition-colors duration-200" href="../about.php">Help Center</a>
                    </li>
                    <li><a class="hover:text-white transition-colors duration-200" href="../about.php#contact">Contact
                            Us</a></li>
                    <li><a class="hover:text-white transition-colors duration-200"
                            href="../about.php#why-nexusbids">Safety Center</a></li>
                    <li><a class="hover:text-white transition-colors duration-200" href="../about.php">Terms &amp;
                            Conditions</a></li>
                </ul>
            </div>
        </div>
        <div
            class="border-t border-gray-900 py-6 text-center text-xs text-gray-500 max-w-[1600px] mx-auto px-4 flex flex-col sm:flex-row justify-between items-center gap-4">
            <p>© <?php echo date("Y"); ?> NexusBids. All rights reserved.</p>
            <p>Made for Pakistan <span class="text-rose-500"><i class="fa-solid fa-heart"></i></span></p>
        </div>
    </footer>

    <script>
        (function () {
            const html = document.documentElement;
            const themeToggle = document.getElementById('themeToggle');
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                html.classList.add('dark');
            }
            themeToggle?.addEventListener('click', function () {
                html.classList.toggle('dark');
                localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light');
            });

            // Password visibility toggles
            document.querySelectorAll('.password-toggle').forEach(btn => {
                btn.addEventListener('click', function () {
                    const target = document.getElementById(this.dataset.target);
                    if (!target) return;
                    const isPassword = target.type === 'password';
                    target.type = isPassword ? 'text' : 'password';
                    const icon = this.querySelector('i');
                    if (icon) {
                        icon.classList.toggle('fa-eye', !isPassword);
                        icon.classList.toggle('fa-eye-slash', isPassword);
                    }
                });
            });

            // Password strength meter
            const newPwd = document.getElementById('new_password');
            const confirmPwd = document.getElementById('confirm_password');
            const strengthLabel = document.getElementById('strengthLabel');
            const bars = document.querySelectorAll('.strength-bar');
            const reqs = {
                length: v => v.length >= 6,
                upper: v => /[A-Z]/.test(v),
                number: v => /[0-9]/.test(v),
                special: v => /[^A-Za-z0-9]/.test(v),
            };
            const labels = ['Very weak', 'Weak', 'Fair', 'Strong', 'Excellent'];
            const labelColors = ['text-rose-500', 'text-rose-500', 'text-amber-500', 'text-emerald-500', 'text-emerald-600'];
            const barColors = ['bg-rose-500', 'bg-rose-500', 'bg-amber-500', 'bg-emerald-500', 'bg-emerald-600'];

            function evaluateStrength(v) {
                if (!v) return {score: 0, label: '—', color: 'text-gray-400'};
                let score = 0;
                if (reqs.length(v)) score++;
                if (v.length >= 10) score++;
                if (reqs.upper(v)) score++;
                if (reqs.number(v)) score++;
                if (reqs.special(v)) score++;
                score = Math.min(4, score);
                return {score, label: labels[score], color: labelColors[score]};
            }

            newPwd?.addEventListener('input', function () {
                const v = this.value;
                const result = evaluateStrength(v);

                strengthLabel.textContent = result.label;
                strengthLabel.className = 'text-[10px] font-bold ' + result.color;

                bars.forEach((bar, i) => {
                    bar.className = 'h-1.5 rounded-full strength-bar ' + (i < result.score ? barColors[result.score] : 'bg-gray-200 dark:bg-slate-700');
                });

                // Update requirement checks
                document.querySelectorAll('.strength-req').forEach(req => {
                    const key = req.dataset.req;
                    const ok = reqs[key] ? reqs[key](v) : false;
                    const icon = req.querySelector('i');
                    if (ok) {
                        req.classList.remove('text-gray-500', 'dark:text-gray-400');
                        req.classList.add('text-emerald-600', 'dark:text-emerald-400');
                        if (icon) icon.className = 'fa-solid fa-circle-check text-[10px] mr-1.5';
                    } else {
                        req.classList.add('text-gray-500', 'dark:text-gray-400');
                        req.classList.remove('text-emerald-600', 'dark:text-emerald-400');
                        if (icon) icon.className = 'fa-solid fa-circle text-[5px] text-gray-300 mr-1.5';
                    }
                });

                // Check match
                checkMatch();
            });

            function checkMatch() {
                const matchMsg = document.getElementById('matchMessage');
                if (!matchMsg) return;
                if (!confirmPwd.value) {matchMsg.classList.add('hidden'); return;}
                const match = newPwd.value === confirmPwd.value;
                matchMsg.classList.remove('hidden');
                matchMsg.className = 'text-[11px] mt-1.5 flex items-center gap-1.5 ' + (match ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400');
                matchMsg.innerHTML = match
                    ? '<i class="fa-solid fa-circle-check text-[9px]"></i><span>Passwords match</span>'
                    : '<i class="fa-solid fa-circle-xmark text-[9px]"></i><span>Passwords do not match</span>';
            }
            confirmPwd?.addEventListener('input', checkMatch);

            // Avatar file input visual feedback
            const avatarInput = document.getElementById('avatarInput');
            avatarInput?.addEventListener('change', function () {
                const label = this.closest('label');
                if (label && this.files.length) {
                    const name = this.files[0].name;
                    const titleEl = label.querySelector('.text-sm.font-bold');
                    if (titleEl) titleEl.textContent = name;
                }
            });
        })();
    </script>
    <?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>