<?php
session_start();

require_once "../includes/csrf.php";

if (isset($_SESSION['user_id'])) {
    header("Location: ../user/dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | NexusBids</title>
    <meta name="description" content="Create your NexusBids account — Pakistan's modern online auction marketplace.">

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

        /* Role selector cards */
        .role-card {
            position: relative;
            cursor: pointer;
            transition: all .2s ease;
        }

        .role-card input[type="radio"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .role-card input[type="radio"]:checked~.role-card-inner {
            border-color: #6366f1;
            background-color: rgb(99 102 241 / 0.05);
            box-shadow: 0 0 0 3px rgb(99 102 241 / 0.1);
        }

        .dark .role-card input[type="radio"]:checked~.role-card-inner {
            background-color: rgb(99 102 241 / 0.1);
        }

        .role-card input[type="radio"]:checked~.role-card-inner .role-check {
            opacity: 1;
            transform: scale(1);
        }

        .role-check {
            opacity: 0;
            transform: scale(0.5);
            transition: all .2s ease;
        }

        /* Password strength bars */
        .strength-bar {
            transition: background-color .3s ease;
        }
    </style>
</head>

<body
    class="bg-gray-50 text-gray-900 dark:bg-slate-950 dark:text-gray-100 font-sans antialiased selection:bg-indigo-500 selection:text-white min-h-screen">

    <!-- Theme toggle (absolute top-right) -->
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
                <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight text-white group"
                    href="../index.php">
                    <span
                        class="w-11 h-11 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30 group-hover:shadow-indigo-500/50 group-hover:scale-105 transition-all duration-300">
                        <i class="fa-solid fa-gavel text-lg"></i>
                    </span>
                    Nexus<span class="text-indigo-400">Bids</span>
                </a>

                <div class="space-y-6 max-w-lg">
                    <div
                        class="inline-flex items-center gap-2 px-3.5 py-2 rounded-full bg-violet-500/10 border border-violet-500/20 text-violet-400 text-xs font-semibold uppercase tracking-wider">
                        <i class="fa-solid fa-sparkles text-[10px]"></i>
                        Join Thousands of Users
                    </div>
                    <h2 class="text-4xl xl:text-5xl font-black tracking-tight leading-[1.1]">
                        Start Your<br>
                        <span class="gradient-text bg-gradient-to-r from-blue-400 via-indigo-400 to-violet-400">Auction
                            Journey.</span>
                    </h2>
                    <p class="text-gray-300 text-base leading-relaxed">Create a free account and start bidding or
                        selling on Pakistan's modern auction marketplace.</p>

                    <!-- Feature list -->
                    <div class="space-y-3.5 pt-4">
                        <div class="flex items-center gap-3">
                            <div
                                class="w-9 h-9 rounded-xl bg-indigo-500/20 text-indigo-400 flex items-center justify-center text-sm shrink-0">
                                <i class="fa-solid fa-user-plus"></i>
                            </div>
                            <span class="text-sm text-gray-300">Free registration, no hidden fees</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <div
                                class="w-9 h-9 rounded-xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-sm shrink-0">
                                <i class="fa-solid fa-shield-halved"></i>
                            </div>
                            <span class="text-sm text-gray-300">Secure &amp; encrypted account protection</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <div
                                class="w-9 h-9 rounded-xl bg-violet-500/20 text-violet-400 flex items-center justify-center text-sm shrink-0">
                                <i class="fa-solid fa-store"></i>
                            </div>
                            <span class="text-sm text-gray-300">Buy or sell — choose your role</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <div
                                class="w-9 h-9 rounded-xl bg-amber-500/20 text-amber-400 flex items-center justify-center text-sm shrink-0">
                                <i class="fa-solid fa-bolt"></i>
                            </div>
                            <span class="text-sm text-gray-300">Real-time bidding &amp; instant notifications</span>
                        </div>
                    </div>
                </div>

                <!-- Testimonial / Social proof -->
                <div class="pt-6 border-t border-white/10">
                    <div class="flex items-center gap-4">
                        <div class="flex -space-x-2 shrink-0">
                            <div
                                class="w-9 h-9 rounded-full border-2 border-slate-950 bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center text-[10px] font-bold">
                                AK</div>
                            <div
                                class="w-9 h-9 rounded-full border-2 border-slate-950 bg-gradient-to-br from-violet-500 to-violet-700 flex items-center justify-center text-[10px] font-bold">
                                SF</div>
                            <div
                                class="w-9 h-9 rounded-full border-2 border-slate-950 bg-gradient-to-br from-blue-500 to-blue-700 flex items-center justify-center text-[10px] font-bold">
                                MR</div>
                            <div
                                class="w-9 h-9 rounded-full border-2 border-slate-950 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-[10px] font-bold">
                                +</div>
                        </div>
                        <p class="text-xs text-gray-400 leading-snug">
                            <strong class="text-white">10,000+ bidders</strong> already joined<br>
                            NexusBids across Pakistan.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT: Register Form -->
        <div
            class="flex items-start justify-center p-6 sm:p-10 lg:p-14 bg-gray-50 dark:bg-slate-950 relative overflow-hidden">
            <div
                class="absolute top-0 right-0 w-96 h-96 bg-indigo-500/5 dark:bg-indigo-600/10 rounded-full blur-[100px] pointer-events-none">
            </div>
            <div
                class="absolute bottom-0 left-0 w-96 h-96 bg-violet-500/5 dark:bg-violet-600/10 rounded-full blur-[100px] pointer-events-none">
            </div>

            <div class="relative w-full max-w-md animate-fade-up py-8">

                <!-- Mobile logo -->
                <div
                    class="lg:hidden flex items-center justify-center gap-2.5 text-2xl font-black tracking-tight text-gray-900 dark:text-white mb-8">
                    <span
                        class="w-11 h-11 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30">
                        <i class="fa-solid fa-gavel text-lg"></i>
                    </span>
                    Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
                </div>

                <!-- Header -->
                <div class="text-center lg:text-left mb-8">
                    <h1 class="text-3xl sm:text-4xl font-black tracking-tight text-gray-900 dark:text-white">Create
                        Account</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Join Pakistan's modern online auction
                        marketplace.</p>
                </div>

                <!-- Error flash -->
                <?php if (isset($_SESSION['error'])): ?>
                    <div
                        class="flex items-start gap-3 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 dark:border-rose-500/20 mb-6">
                        <div
                            class="w-9 h-9 rounded-lg bg-rose-500/20 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0">
                            <i class="fa-solid fa-circle-exclamation"></i>
                        </div>
                        <p class="text-sm text-rose-700 dark:text-rose-300 font-medium self-center">
                            <?php echo htmlspecialchars($_SESSION['error']);
                            unset($_SESSION['error']); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Form -->
                <form action="register_process.php" method="POST" class="space-y-5" id="registerForm">
                    <?php nb_csrf_field(); ?>

                    <!-- Full Name -->
                    <div>
                        <label for="full_name"
                            class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-2">
                            Full Name <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative group">
                            <i
                                class="fa-solid fa-user absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 group-focus-within:text-indigo-500 transition-colors text-sm"></i>
                            <input type="text" name="full_name" id="full_name" placeholder="Enter your full name"
                                required autocomplete="name"
                                class="w-full pl-11 pr-4 py-3.5 bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 rounded-xl text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all duration-200 placeholder:text-gray-400">
                        </div>
                    </div>

                    <!-- Email -->
                    <div>
                        <label for="email"
                            class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-2">
                            Email Address <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative group">
                            <i
                                class="fa-solid fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 group-focus-within:text-indigo-500 transition-colors text-sm"></i>
                            <input type="email" name="email" id="email" placeholder="you@example.com" required
                                autocomplete="email"
                                class="w-full pl-11 pr-4 py-3.5 bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 rounded-xl text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all duration-200 placeholder:text-gray-400">
                        </div>
                    </div>

                    <!-- Phone -->
                    <div>
                        <label for="phone"
                            class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-2">
                            Phone Number <span
                                class="text-gray-400 font-normal normal-case tracking-normal">(optional)</span>
                        </label>
                        <div class="relative group">
                            <span
                                class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 group-focus-within:text-indigo-500 transition-colors text-xs font-bold">PK</span>
                            <input type="tel" name="phone" id="phone" placeholder="03XX XXXXXXX" autocomplete="tel"
                                class="w-full pl-12 pr-4 py-3.5 bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 rounded-xl text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all duration-200 placeholder:text-gray-400">
                        </div>
                    </div>

                    <!-- Password -->
                    <div>
                        <label for="password"
                            class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-2">
                            Password <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative group">
                            <i
                                class="fa-solid fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 group-focus-within:text-indigo-500 transition-colors text-sm"></i>
                            <input type="password" name="password" id="password" placeholder="Create a password"
                                required autocomplete="new-password"
                                class="w-full pl-11 pr-12 py-3.5 bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 rounded-xl text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all duration-200 placeholder:text-gray-400">
                            <button type="button"
                                class="password-toggle absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
                                data-target="password" aria-label="Toggle password visibility">
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
                                        class="fa-solid fa-circle text-[5px] text-gray-300 mr-1.5"></i>Contains number
                                </li>
                                <li class="strength-req" data-req="special"><i
                                        class="fa-solid fa-circle text-[5px] text-gray-300 mr-1.5"></i>Contains special
                                    character</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Confirm Password -->
                    <div>
                        <label for="confirm_password"
                            class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-2">
                            Confirm Password <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative group">
                            <i
                                class="fa-solid fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 group-focus-within:text-indigo-500 transition-colors text-sm"></i>
                            <input type="password" name="confirm_password" id="confirm_password"
                                placeholder="Confirm your password" required autocomplete="new-password"
                                class="w-full pl-11 pr-12 py-3.5 bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 rounded-xl text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all duration-200 placeholder:text-gray-400">
                            <button type="button"
                                class="password-toggle absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
                                data-target="confirm_password" aria-label="Toggle password visibility">
                                <i class="fa-regular fa-eye text-sm"></i>
                            </button>
                        </div>
                        <p id="matchMessage" class="text-[11px] mt-2 hidden flex items-center gap-1.5">
                            <i class="text-[9px]"></i><span></span>
                        </p>
                    </div>

                    <!-- Account Type -->
                    <div>
                        <label
                            class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-3">
                            Account Type <span class="text-rose-500">*</span>
                        </label>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="role-card block">
                                <input type="radio" name="role" value="user" checked>
                                <div
                                    class="role-card-inner relative p-4 rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-slate-900 hover:border-indigo-300 dark:hover:border-indigo-800 transition-all duration-200">
                                    <div class="flex items-center justify-between mb-3">
                                        <div
                                            class="w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm">
                                            <i class="fa-solid fa-gavel"></i>
                                        </div>
                                        <div
                                            class="role-check w-5 h-5 rounded-full bg-indigo-600 flex items-center justify-center">
                                            <i class="fa-solid fa-check text-white text-[9px]"></i>
                                        </div>
                                    </div>
                                    <h3 class="font-bold text-sm text-gray-900 dark:text-white">Buyer</h3>
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">Bid on auctions</p>
                                </div>
                            </label>

                            <label class="role-card block">
                                <input type="radio" name="role" value="seller">
                                <div
                                    class="role-card-inner relative p-4 rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-slate-900 hover:border-indigo-300 dark:hover:border-indigo-800 transition-all duration-200">
                                    <div class="flex items-center justify-between mb-3">
                                        <div
                                            class="w-9 h-9 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-sm">
                                            <i class="fa-solid fa-store"></i>
                                        </div>
                                        <div
                                            class="role-check w-5 h-5 rounded-full bg-indigo-600 flex items-center justify-center">
                                            <i class="fa-solid fa-check text-white text-[9px]"></i>
                                        </div>
                                    </div>
                                    <h3 class="font-bold text-sm text-gray-900 dark:text-white">Seller</h3>
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">List &amp; sell items
                                    </p>
                                </div>
                            </label>
                        </div>
                        <!-- Hidden select to preserve server-side behavior -->
                        <select name="role_backup" id="role-select" class="sr-only" tabindex="-1" aria-hidden="true">
                            <option value="user">Buyer / Bidder</option>
                            <option value="seller">Seller</option>
                        </select>
                    </div>

                    <!-- Terms -->
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" id="terms" name="terms" required
                            class="mt-0.5 w-4 h-4 rounded border-gray-300 dark:border-gray-700 text-indigo-600 focus:ring-indigo-500 dark:bg-slate-800 shrink-0">
                        <label for="terms"
                            class="text-xs text-gray-600 dark:text-gray-400 leading-relaxed cursor-pointer">
                            I agree to the
                            <a href="../about.php"
                                class="font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">Terms of
                                Service</a>
                            and
                            <a href="../about.php"
                                class="font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">Privacy
                                Policy</a>
                        </label>
                    </div>

                    <!-- Submit -->
                    <button type="submit"
                        class="group w-full py-3.5 px-6 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white font-bold text-sm shadow-lg shadow-indigo-600/30 hover:shadow-indigo-500/50 transition-all duration-300 hover:-translate-y-0.5 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-user-plus"></i>
                        Create Account
                        <i
                            class="fa-solid fa-arrow-right text-xs group-hover:translate-x-1 transition-transform duration-300"></i>
                    </button>
                </form>

                <!-- Divider -->
                <div class="relative my-6">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-gray-200 dark:border-gray-800"></div>
                    </div>
                    <div class="relative flex justify-center">
                        <span
                            class="px-3 bg-gray-50 dark:bg-slate-950 text-[11px] font-bold uppercase tracking-wider text-gray-400">
                            or continue with
                        </span>
                    </div>
                </div>

                <!-- Google button -->
                <a href="google-login.php" id="google-signup-link"
                    class="group flex items-center justify-center gap-3 w-full py-3.5 px-6 rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 hover:border-gray-300 dark:hover:border-gray-700 hover:bg-gray-50 dark:hover:bg-slate-800 text-gray-700 dark:text-gray-200 font-semibold text-sm transition-all duration-200 hover:shadow-md">
                    <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg" class="shrink-0">
                        <path fill="#4285F4"
                            d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844a4.14 4.14 0 0 1-1.796 2.716v2.259h2.908c1.702-1.567 2.684-3.875 2.684-6.615Z" />
                        <path fill="#34A853"
                            d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9 18Z" />
                        <path fill="#FBBC05"
                            d="M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332Z" />
                        <path fill="#EA4335"
                            d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.958L3.964 7.29C4.672 5.163 6.656 3.58 9 3.58Z" />
                    </svg>
                    <span>Continue with Google</span>
                </a>

                <!-- Sign in link -->
                <p class="text-center text-sm text-gray-500 dark:text-gray-400 mt-8">
                    Already have an account?
                    <a href="login.php" class="font-bold text-indigo-600 dark:text-indigo-400 hover:underline">
                        Sign In
                    </a>
                </p>

                <!-- Trust badges -->
                <div class="mt-8 pt-6 border-t border-gray-200 dark:border-gray-800">
                    <div
                        class="flex flex-wrap items-center justify-center gap-x-6 gap-y-3 text-[10px] text-gray-400 uppercase tracking-wider font-semibold">
                        <span class="flex items-center gap-1.5">
                            <i class="fa-solid fa-shield-halved text-emerald-500"></i>
                            SSL Secured
                        </span>
                        <span class="flex items-center gap-1.5">
                            <i class="fa-solid fa-lock text-indigo-500"></i>
                            Encrypted
                        </span>
                        <span class="flex items-center gap-1.5">
                            <i class="fa-solid fa-circle-check text-blue-500"></i>
                            No Fees
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
            const newPwd = document.getElementById('password');
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

                checkMatch();
            });

            function checkMatch() {
                const matchMsg = document.getElementById('matchMessage');
                if (!matchMsg) return;
                if (!confirmPwd.value) {matchMsg.classList.add('hidden'); return;}
                const match = newPwd.value === confirmPwd.value;
                matchMsg.classList.remove('hidden');
                matchMsg.className = 'text-[11px] mt-2 flex items-center gap-1.5 ' + (match ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400');
                matchMsg.innerHTML = match
                    ? '<i class="fa-solid fa-circle-check text-[9px]"></i><span>Passwords match</span>'
                    : '<i class="fa-solid fa-circle-xmark text-[9px]"></i><span>Passwords do not match</span>';
            }
            confirmPwd?.addEventListener('input', checkMatch);

            // Role sync with Google signup link
            const roleRadios = document.querySelectorAll('input[name="role"]');
            const googleLink = document.getElementById('google-signup-link');
            const roleSelectBackup = document.getElementById('role-select');

            function syncRole() {
                const selected = document.querySelector('input[name="role"]:checked');
                const value = selected ? selected.value : 'user';
                if (googleLink) googleLink.href = 'google-login.php?role=' + encodeURIComponent(value);
                if (roleSelectBackup) roleSelectBackup.value = value;
            }
            roleRadios.forEach(r => r.addEventListener('change', syncRole));
            syncRole();
        })();
    </script>
</body>

</html>