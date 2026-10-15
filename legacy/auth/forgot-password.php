<?php

session_start();

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/rate-limit.php";
require_once "../includes/mailer.php";

$notice = "";
$notice_type = "info"; // info | success | error

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    nb_csrf_check();

    if (!nb_throttle("forgot_" . ($_SERVER["REMOTE_ADDR"] ?? "unknown"), 5, 300)) {
        $notice = "Too many requests. Please try again later.";
        $notice_type = "error";
    } else {
        $email = trim($_POST["email"] ?? "");

        if (!empty($email)) {
            $stmt = $conn->prepare("SELECT user_id, full_name FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                $reset_token = bin2hex(random_bytes(32));
                $expires = date("Y-m-d H:i:s", time() + 3600);

                $update_stmt = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE user_id = ?");
                $update_stmt->bind_param("ssi", $reset_token, $expires, $user["user_id"]);
                $update_stmt->execute();

                $reset_link = "http://" . $_SERVER["HTTP_HOST"] . dirname(dirname($_SERVER["SCRIPT_NAME"])) . "/auth/reset-password.php?token=" . $reset_token;

                nb_send_mail(
                    $email,
                    "Reset your NexusBids password",
                    "<p>Hi " . htmlspecialchars($user["full_name"]) . ",</p>" .
                    "<p>Click the link below to reset your password. This link expires in 1 hour.</p>" .
                    "<p><a href=\"" . htmlspecialchars($reset_link) . "\">Reset my password</a></p>"
                );
            }

            // Same message whether or not the email exists — don't leak which emails are registered.
            $notice = "If that email is registered, a reset link has been sent to it.";
            $notice_type = "success";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | NexusBids</title>
    <meta name="description" content="Reset your NexusBids password — we'll send a secure reset link to your email.">

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
                        'float': 'float 6s ease-in-out infinite',
                    },
                    keyframes: {
                        fadeUp: {'0%': {opacity: '0', transform: 'translateY(16px)'}, '100%': {opacity: '1', transform: 'translateY(0)'}},
                        fadeIn: {'0%': {opacity: '0'}, '100%': {opacity: '1'}},
                        pulseSoft: {'0%, 100%': {opacity: '1'}, '50%': {opacity: '0.5'}},
                        float: {'0%, 100%': {transform: 'translateY(0)'}, '50%': {transform: 'translateY(-10px)'}},
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
    </style>
</head>

<body
    class="bg-gray-50 text-gray-900 dark:bg-slate-950 dark:text-gray-100 font-sans antialiased selection:bg-indigo-500 selection:text-white min-h-screen">

    <!-- Theme toggle -->
    <button aria-label="Toggle Theme" id="themeToggle"
        class="fixed top-4 right-4 z-50 w-10 h-10 rounded-full bg-white/90 dark:bg-slate-900/90 glass border border-gray-200 dark:border-gray-800 shadow-lg text-gray-700 dark:text-gray-300 flex items-center justify-center hover:scale-110 transition-all duration-200">
        <i class="fa-solid fa-moon dark:hidden"></i>
        <i class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
    </button>

    <div class="min-h-screen grid grid-cols-1 lg:grid-cols-2">

        <!-- LEFT: Brand Panel -->
        <div class="hidden lg:flex relative bg-slate-950 text-white overflow-hidden noise-overlay">
            <div
                class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-indigo-900/40 via-slate-950 to-slate-950">
            </div>
            <div
                class="absolute top-0 right-1/4 w-[500px] h-[500px] bg-indigo-600/20 rounded-full blur-[120px] pointer-events-none animate-float">
            </div>
            <div
                class="absolute bottom-0 left-1/4 w-[400px] h-[400px] bg-violet-600/15 rounded-full blur-[100px] pointer-events-none">
            </div>
            <div
                class="absolute top-1/2 left-0 w-[300px] h-[300px] bg-blue-600/10 rounded-full blur-[80px] pointer-events-none">
            </div>
            <div class="absolute inset-0 opacity-[0.03]"
                style="background-image: linear-gradient(rgba(255,255,255,.1) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.1) 1px, transparent 1px); background-size: 60px 60px;">
            </div>

            <div class="relative z-10 flex flex-col justify-between w-full p-10 lg:p-14">
                <!-- Logo -->
                <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight text-white group"
                    href="../index.php">
                    <span
                        class="w-11 h-11 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30 group-hover:shadow-indigo-500/50 group-hover:scale-105 transition-all duration-300">
                        <i class="fa-solid fa-gavel text-lg"></i>
                    </span>
                    Nexus<span class="text-indigo-400">Bids</span>
                </a>

                <!-- Content -->
                <div class="space-y-6 max-w-lg">
                    <div
                        class="inline-flex items-center gap-2 px-3.5 py-2 rounded-full bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 text-xs font-semibold uppercase tracking-wider">
                        <i class="fa-solid fa-lock text-[10px]"></i>
                        Secure Account Recovery
                    </div>
                    <h2 class="text-4xl xl:text-5xl font-black tracking-tight leading-[1.1]">
                        Forgot Your<br>
                        <span
                            class="gradient-text bg-gradient-to-r from-blue-400 via-indigo-400 to-violet-400">Password?</span>
                    </h2>
                    <p class="text-gray-300 text-base leading-relaxed">No worries — it happens to the best of us. Enter
                        your registered email and we'll send you a secure link to reset your password.</p>

                    <!-- Steps -->
                    <div class="space-y-3.5 pt-4">
                        <div class="flex items-center gap-3">
                            <div
                                class="w-9 h-9 rounded-xl bg-indigo-500/20 text-indigo-400 flex items-center justify-center text-sm font-bold shrink-0">
                                1
                            </div>
                            <span class="text-sm text-gray-300">Enter the email linked to your account</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <div
                                class="w-9 h-9 rounded-xl bg-violet-500/20 text-violet-400 flex items-center justify-center text-sm font-bold shrink-0">
                                2
                            </div>
                            <span class="text-sm text-gray-300">Check your inbox for our reset email</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <div
                                class="w-9 h-9 rounded-xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-sm font-bold shrink-0">
                                3
                            </div>
                            <span class="text-sm text-gray-300">Create a new password and sign back in</span>
                        </div>
                    </div>
                </div>

                <!-- Security note -->
                <div class="flex items-center gap-4 pt-6 border-t border-white/10">
                    <div
                        class="w-11 h-11 rounded-xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-lg shrink-0">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <p class="text-xs text-gray-400 leading-snug">
                        <strong class="text-white">Security first.</strong><br>
                        Reset links expire in 1 hour and can only be used once.
                    </p>
                </div>
            </div>
        </div>

        <!-- RIGHT: Form -->
        <div
            class="flex items-center justify-center p-6 sm:p-10 lg:p-14 bg-gray-50 dark:bg-slate-950 relative overflow-hidden">
            <div
                class="absolute top-0 right-0 w-96 h-96 bg-indigo-500/5 dark:bg-indigo-600/10 rounded-full blur-[100px] pointer-events-none">
            </div>
            <div
                class="absolute bottom-0 left-0 w-96 h-96 bg-violet-500/5 dark:bg-violet-600/10 rounded-full blur-[100px] pointer-events-none">
            </div>

            <div class="relative w-full max-w-md animate-fade-up">

                <!-- Mobile logo -->
                <div
                    class="lg:hidden flex items-center justify-center gap-2.5 text-2xl font-black tracking-tight text-gray-900 dark:text-white mb-8">
                    <span
                        class="w-11 h-11 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30">
                        <i class="fa-solid fa-gavel text-lg"></i>
                    </span>
                    Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
                </div>

                <!-- Back link -->
                <a href="login.php"
                    class="inline-flex items-center gap-2 text-xs font-semibold text-gray-500 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors mb-6">
                    <i class="fa-solid fa-arrow-left text-[10px]"></i>
                    Back to Login
                </a>

                <!-- Header -->
                <div class="text-center lg:text-left mb-8">
                    <h1 class="text-3xl sm:text-4xl font-black tracking-tight text-gray-900 dark:text-white">Forgot
                        Password</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Enter your account email and we'll send you
                        a reset link.</p>
                </div>

                <!-- Notice -->
                <?php if ($notice): ?>
                    <?php
                    $notice_styles = [
                        'success' => ['bg' => 'bg-emerald-500/10', 'border' => 'border-emerald-500/30 dark:border-emerald-500/20', 'icon_bg' => 'bg-emerald-500/20', 'icon_color' => 'text-emerald-600 dark:text-emerald-400', 'text' => 'text-emerald-700 dark:text-emerald-300', 'icon' => 'fa-circle-check'],
                        'error' => ['bg' => 'bg-rose-500/10', 'border' => 'border-rose-500/30 dark:border-rose-500/20', 'icon_bg' => 'bg-rose-500/20', 'icon_color' => 'text-rose-600 dark:text-rose-400', 'text' => 'text-rose-700 dark:text-rose-300', 'icon' => 'fa-circle-exclamation'],
                        'info' => ['bg' => 'bg-blue-500/10', 'border' => 'border-blue-500/30 dark:border-blue-500/20', 'icon_bg' => 'bg-blue-500/20', 'icon_color' => 'text-blue-600 dark:text-blue-400', 'text' => 'text-blue-700 dark:text-blue-300', 'icon' => 'fa-circle-info'],
                    ];
                    $ns = $notice_styles[$notice_type] ?? $notice_styles['info'];
                    ?>
                    <div
                        class="flex items-start gap-3 p-4 rounded-2xl <?php echo $ns['bg']; ?> border <?php echo $ns['border']; ?> mb-6 animate-fade-in">
                        <div
                            class="w-9 h-9 rounded-lg <?php echo $ns['icon_bg']; ?> <?php echo $ns['icon_color']; ?> flex items-center justify-center shrink-0">
                            <i class="fa-solid <?php echo $ns['icon']; ?>"></i>
                        </div>
                        <p class="text-sm <?php echo $ns['text']; ?> font-medium self-center">
                            <?php echo htmlspecialchars($notice); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Form -->
                <form method="POST" class="space-y-5" id="forgotForm">
                    <?php nb_csrf_field(); ?>

                    <!-- Email -->
                    <div>
                        <label for="email"
                            class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-2">
                            Email Address
                        </label>
                        <div class="relative group">
                            <i
                                class="fa-solid fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 group-focus-within:text-indigo-500 transition-colors text-sm"></i>
                            <input type="email" name="email" id="email" placeholder="you@example.com" required
                                autocomplete="email" autofocus
                                class="w-full pl-11 pr-4 py-3.5 bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 rounded-xl text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all duration-200 placeholder:text-gray-400">
                        </div>
                        <p class="text-[11px] text-gray-400 mt-1.5 flex items-center gap-1.5">
                            <i class="fa-solid fa-circle-info text-[9px]"></i>
                            Use the email you registered with.
                        </p>
                    </div>

                    <!-- Submit -->
                    <button type="submit"
                        class="group w-full py-3.5 px-6 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white font-bold text-sm shadow-lg shadow-indigo-600/30 hover:shadow-indigo-500/50 transition-all duration-300 hover:-translate-y-0.5 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-paper-plane"></i>
                        Send Reset Link
                        <i
                            class="fa-solid fa-arrow-right text-xs group-hover:translate-x-1 transition-transform duration-300"></i>
                    </button>
                </form>

                <!-- Help section -->
                <div
                    class="mt-8 p-4 rounded-2xl bg-indigo-50/50 dark:bg-indigo-950/20 border border-indigo-100 dark:border-indigo-900/30">
                    <div class="flex items-start gap-3">
                        <div
                            class="w-8 h-8 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm shrink-0">
                            <i class="fa-solid fa-lightbulb"></i>
                        </div>
                        <div>
                            <h4 class="font-bold text-gray-900 dark:text-white text-xs">Didn't get the email?</h4>
                            <ul class="text-[11px] text-gray-600 dark:text-gray-400 mt-1.5 space-y-1 leading-relaxed">
                                <li class="flex items-start gap-1.5">
                                    <i class="fa-solid fa-circle text-[4px] text-indigo-500 mt-1.5"></i>
                                    Check your spam or junk folder
                                </li>
                                <li class="flex items-start gap-1.5">
                                    <i class="fa-solid fa-circle text-[4px] text-indigo-500 mt-1.5"></i>
                                    Make sure you typed the correct email
                                </li>
                                <li class="flex items-start gap-1.5">
                                    <i class="fa-solid fa-circle text-[4px] text-indigo-500 mt-1.5"></i>
                                    Wait a few minutes before retrying
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Bottom links -->
                <div class="text-center mt-8 space-y-3">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Remembered your password?
                        <a href="login.php" class="font-bold text-indigo-600 dark:text-indigo-400 hover:underline">
                            Sign In
                        </a>
                    </p>
                    <p class="text-xs text-gray-400">
                        Don't have an account?
                        <a href="register.php"
                            class="font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">
                            Create one
                        </a>
                    </p>
                </div>

                <!-- Trust badges -->
                <div class="mt-8 pt-6 border-t border-gray-200 dark:border-gray-800">
                    <div
                        class="flex flex-wrap items-center justify-center gap-x-6 gap-y-3 text-[10px] text-gray-400 uppercase tracking-wider font-semibold">
                        <span class="flex items-center gap-1.5">
                            <i class="fa-solid fa-shield-halved text-emerald-500"></i>
                            SSL Secured
                        </span>
                        <span class="flex items-center gap-1.5">
                            <i class="fa-solid fa-clock text-indigo-500"></i>
                            1hr Expiry
                        </span>
                        <span class="flex items-center gap-1.5">
                            <i class="fa-solid fa-lock text-blue-500"></i>
                            Encrypted
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
        })();
    </script>
</body>

</html>