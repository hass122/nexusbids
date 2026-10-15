<?php
session_start();
date_default_timezone_set('Asia/Karachi');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";
require_once "../includes/kyc-helpers.php";

$user_id = (int) $_SESSION['user_id'];

// Check eligibility
$eligibility = nb_kyc_can_submit($conn, $user_id);
if (!$eligibility['ok']) {
    $_SESSION['flash_error'] = $eligibility['reason'];
    header("Location: kyc-status.php");
    exit();
}

// Load user
$u_stmt = $conn->prepare("SELECT full_name, email, avatar, phone FROM users WHERE user_id = ? LIMIT 1");
$u_stmt->bind_param("i", $user_id);
$u_stmt->execute();
$u = $u_stmt->get_result()->fetch_assoc();
$u_stmt->close();

$full_name = $u['full_name'] ?? 'User';
$email = $u['email'] ?? '';
$avatar = $u['avatar'] ?? '';
$phone = $u['phone'] ?? '';
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));
$unread_notifications = nb_unread_count($conn, $user_id);

// Flash
$error = $_SESSION['kyc_error'] ?? '';
$success = $_SESSION['kyc_success'] ?? '';
unset($_SESSION['kyc_error'], $_SESSION['kyc_success']);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Identity Verification · NexusBids</title>
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {extend: {fontFamily: {sans: ['Inter', 'system-ui', 'sans-serif']}}}
        };
        (function () {try {const s = localStorage.getItem('theme'); const d = window.matchMedia('(prefers-color-scheme: dark)').matches; if (s === 'dark' || (!s && d)) document.documentElement.classList.add('dark');} catch (e) { } })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        html {
            scroll-behavior: smooth
        }

        body {
            font-feature-settings: 'cv02', 'cv03', 'cv04', 'cv11'
        }

        .glass {
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%)
        }

        ::-webkit-scrollbar {
            width: 8px;
            height: 8px
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px
        }

        .dark ::-webkit-scrollbar-thumb {
            background: #334155
        }

        .step-indicator {
            cursor: pointer;
            transition: all .2s ease
        }

        .step-active .step-dot {
            background: #4f46e5;
            color: #fff;
            box-shadow: 0 0 0 6px rgba(99, 102, 241, .15)
        }

        .step-done .step-dot {
            background: #10b981;
            color: #fff
        }

        .step-dot {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #e5e7eb;
            color: #6b7280;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            transition: all .25s ease
        }

        .dark .step-dot {
            background: #334155;
            color: #94a3b8
        }

        .upload-box {
            border: 2px dashed #cbd5e1;
            border-radius: 16px;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: all .2s ease
        }

        .upload-box:hover {
            border-color: #6366f1;
            background: rgba(99, 102, 241, .04)
        }

        .dark .upload-box {
            border-color: #475569
        }

        .upload-box.has-file {
            border-color: #10b981;
            background: rgba(16, 185, 129, .05)
        }

        .preview-img {
            max-height: 220px;
            border-radius: 12px;
            margin-top: 12px;
            object-fit: contain
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 font-sans antialiased min-h-screen">

    <!-- Top Bar -->
    <div class="bg-gray-950 text-gray-400 text-xs py-2.5 px-4 sm:px-8 border-b border-gray-800/50">
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-2">
            <div class="flex items-center space-x-6">
                <span class="flex items-center gap-2"><i class="fa-solid fa-location-dot text-indigo-400"></i>
                    Pakistan</span>
                <span class="hidden sm:flex items-center gap-2"><i
                        class="fa-solid fa-shield-halved text-emerald-400"></i> Secure KYC</span>
            </div>
            <div class="flex items-center space-x-6">
                <a href="../about.php" class="hover:text-white"><i
                        class="fa-regular fa-circle-question text-indigo-400"></i> Help</a>
            </div>
        </div>
    </div>

    <!-- Navbar -->
    <header
        class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
            <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight shrink-0" href="../index.php">
                <span
                    class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg"><i
                        class="fa-solid fa-gavel text-lg"></i></span>
                Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
            </a>
            <div class="flex items-center gap-2 sm:gap-3">
                <button id="themeToggle"
                    class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                    <i class="fa-solid fa-moon dark:hidden"></i><i
                        class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                </button>
                <a href="dashboard.php"
                    class="hidden sm:inline-flex px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:text-indigo-600 transition">
                    <i class="fa-solid fa-arrow-left mr-1.5"></i> Back
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-4xl mx-auto px-4 sm:px-8 py-8">

        <!-- Header -->
        <div class="mb-8 text-center">
            <div
                class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-indigo-500/10 border border-indigo-500/20 text-indigo-600 dark:text-indigo-400 text-xs font-bold uppercase tracking-wider mb-4">
                <i class="fa-solid fa-shield-halved"></i> Secure Verification
            </div>
            <h1 class="text-3xl sm:text-4xl font-black tracking-tight">Verify Your Identity</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-3 max-w-xl mx-auto leading-relaxed">
                Complete KYC to unlock higher bidding limits, faster payouts, and full platform access. Takes about 2
                minutes.
            </p>
        </div>

        <!-- Flash -->
        <?php if ($error): ?>
            <div
                class="mb-6 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-700 dark:text-rose-300 flex items-center gap-3">
                <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Step Indicator -->
        <div class="mb-8 bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-5">
            <div class="flex items-center justify-between">
                <?php
                $steps = [
                    ['num' => 1, 'label' => 'Personal Info', 'icon' => 'fa-user'],
                    ['num' => 2, 'label' => 'CNIC Photos', 'icon' => 'fa-id-card'],
                    ['num' => 3, 'label' => 'Selfie', 'icon' => 'fa-camera'],
                    ['num' => 4, 'label' => 'Review', 'icon' => 'fa-check'],
                ];
                foreach ($steps as $i => $s):
                    ?>
                    <div class="flex items-center flex-1 <?= $i < count($steps) - 1 ? '' : 'flex-none' ?>">
                        <div class="step-indicator <?= $s['num'] === 1 ? 'step-active' : '' ?>"
                            data-step="<?= $s['num'] ?>">
                            <div class="step-dot"><i class="fa-solid <?= $s['icon'] ?> text-xs"></i></div>
                            <div
                                class="hidden sm:block text-[11px] font-bold text-gray-500 dark:text-gray-400 mt-2 text-center whitespace-nowrap">
                                <?= $s['label'] ?></div>
                        </div>
                        <?php if ($i < count($steps) - 1): ?>
                            <div class="flex-1 h-0.5 bg-gray-200 dark:bg-slate-700 mx-2 sm:mx-3"></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Form -->
        <form id="kycForm" method="POST" action="kyc-submit.php" enctype="multipart/form-data"
            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-6 sm:p-8">
            <?php nb_csrf_field(); ?>
            <input type="hidden" name="kyc_scope" value="buyer">

            <!-- ============ STEP 1: Personal Info ============ -->
            <div class="kyc-step" data-step="1">
                <div class="mb-6">
                    <h2 class="text-xl font-black flex items-center gap-3">
                        <span
                            class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center"><i
                                class="fa-solid fa-user"></i></span>
                        Personal Information
                    </h2>
                    <p class="text-xs text-gray-500 mt-2">Enter details exactly as they appear on your CNIC.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div class="sm:col-span-2">
                        <label
                            class="block text-xs font-bold uppercase tracking-wider text-gray-600 dark:text-gray-300 mb-2">
                            Full Name (as on CNIC) <span class="text-rose-500">*</span>
                        </label>
                        <input type="text" name="full_name_on_cnic" id="full_name_on_cnic" required maxlength="150"
                            value="<?= htmlspecialchars($full_name) ?>"
                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div class="sm:col-span-2">
                        <label
                            class="block text-xs font-bold uppercase tracking-wider text-gray-600 dark:text-gray-300 mb-2">
                            CNIC Number <span class="text-rose-500">*</span>
                        </label>
                        <input type="text" name="cnic_number" id="cnic_number" required placeholder="42101-1234567-8"
                            maxlength="15" inputmode="numeric" pattern="\d{5}-?\d{7}-?\d"
                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono tracking-wider">
                        <p class="text-[11px] text-gray-400 mt-1.5"><i class="fa-solid fa-circle-info text-[9px]"></i>
                            Format: 5-7-1 digits (auto-formatted)</p>
                    </div>

                    <div>
                        <label
                            class="block text-xs font-bold uppercase tracking-wider text-gray-600 dark:text-gray-300 mb-2">
                            Date of Birth <span class="text-rose-500">*</span>
                        </label>
                        <input type="date" name="date_of_birth" id="date_of_birth" required
                            max="<?= date('Y-m-d', strtotime('-18 years')) ?>"
                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <p class="text-[11px] text-gray-400 mt-1.5">You must be 18 or older.</p>
                    </div>

                    <div>
                        <label
                            class="block text-xs font-bold uppercase tracking-wider text-gray-600 dark:text-gray-300 mb-2">
                            Phone Number
                        </label>
                        <input type="tel" name="phone" id="phone" maxlength="20" value="<?= htmlspecialchars($phone) ?>"
                            placeholder="03XX-XXXXXXX"
                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div class="sm:col-span-2">
                        <label
                            class="block text-xs font-bold uppercase tracking-wider text-gray-600 dark:text-gray-300 mb-2">
                            Father's Name (as on CNIC)
                        </label>
                        <input type="text" name="father_name" id="father_name" maxlength="150"
                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div class="sm:col-span-2">
                        <label
                            class="block text-xs font-bold uppercase tracking-wider text-gray-600 dark:text-gray-300 mb-2">
                            Address (as on CNIC)
                        </label>
                        <textarea name="address" id="address" rows="2" maxlength="500"
                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"></textarea>
                    </div>
                </div>
            </div>

            <!-- ============ STEP 2: CNIC Photos ============ -->
            <div class="kyc-step hidden" data-step="2">
                <div class="mb-6">
                    <h2 class="text-xl font-black flex items-center gap-3">
                        <span
                            class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center"><i
                                class="fa-solid fa-id-card"></i></span>
                        CNIC Photos
                    </h2>
                    <p class="text-xs text-gray-500 mt-2">Upload clear photos of both sides. Ensure all text is
                        readable.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label
                            class="block text-xs font-bold uppercase tracking-wider text-gray-600 dark:text-gray-300 mb-2">
                            CNIC Front <span class="text-rose-500">*</span>
                        </label>
                        <label class="upload-box block" id="box_front">
                            <input type="file" name="cnic_front" id="cnic_front"
                                accept="image/jpeg,image/png,image/webp" required class="hidden">
                            <i class="fa-solid fa-cloud-arrow-up text-3xl text-indigo-500 mb-2"></i>
                            <p class="text-sm font-bold text-gray-800 dark:text-gray-100">Click to upload</p>
                            <p class="text-[11px] text-gray-500 mt-1">Front side with photo & number</p>
                            <img id="preview_front" class="preview-img mx-auto hidden" alt="">
                        </label>
                    </div>

                    <div>
                        <label
                            class="block text-xs font-bold uppercase tracking-wider text-gray-600 dark:text-gray-300 mb-2">
                            CNIC Back <span class="text-rose-500">*</span>
                        </label>
                        <label class="upload-box block" id="box_back">
                            <input type="file" name="cnic_back" id="cnic_back" accept="image/jpeg,image/png,image/webp"
                                required class="hidden">
                            <i class="fa-solid fa-cloud-arrow-up text-3xl text-indigo-500 mb-2"></i>
                            <p class="text-sm font-bold text-gray-800 dark:text-gray-100">Click to upload</p>
                            <p class="text-[11px] text-gray-500 mt-1">Back side with address</p>
                            <img id="preview_back" class="preview-img mx-auto hidden" alt="">
                        </label>
                    </div>
                </div>

                <div class="mt-5 p-4 rounded-xl bg-blue-500/5 border border-blue-500/20 flex items-start gap-3">
                    <i class="fa-solid fa-lightbulb text-blue-500 mt-0.5"></i>
                    <div class="text-[11px] text-gray-600 dark:text-gray-400 leading-relaxed">
                        <strong class="text-gray-800 dark:text-gray-200">Photo tips:</strong> Good lighting · No glare ·
                        All 4 corners visible · No blur · No shadows on text
                    </div>
                </div>
            </div>

            <!-- ============ STEP 3: Selfie ============ -->
            <div class="kyc-step hidden" data-step="3">
                <div class="mb-6">
                    <h2 class="text-xl font-black flex items-center gap-3">
                        <span
                            class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center"><i
                                class="fa-solid fa-camera"></i></span>
                        Selfie Verification
                    </h2>
                    <p class="text-xs text-gray-500 mt-2">We'll compare your selfie with the CNIC photo.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label
                            class="block text-xs font-bold uppercase tracking-wider text-gray-600 dark:text-gray-300 mb-2">
                            Selfie (Face Only) <span class="text-rose-500">*</span>
                        </label>
                        <label class="upload-box block" id="box_selfie">
                            <input type="file" name="selfie" id="selfie" accept="image/jpeg,image/png,image/webp"
                                required class="hidden">
                            <i class="fa-solid fa-face-smile text-3xl text-indigo-500 mb-2"></i>
                            <p class="text-sm font-bold text-gray-800 dark:text-gray-100">Click to upload</p>
                            <p class="text-[11px] text-gray-500 mt-1">Clear front-facing selfie</p>
                            <img id="preview_selfie" class="preview-img mx-auto hidden" alt="">
                        </label>
                    </div>

                    <div>
                        <label
                            class="block text-xs font-bold uppercase tracking-wider text-gray-600 dark:text-gray-300 mb-2">
                            Selfie Holding CNIC <span class="text-rose-500">*</span>
                        </label>
                        <label class="upload-box block" id="box_selfie_cnic">
                            <input type="file" name="selfie_with_cnic" id="selfie_with_cnic"
                                accept="image/jpeg,image/png,image/webp" required class="hidden">
                            <i class="fa-solid fa-id-badge text-3xl text-indigo-500 mb-2"></i>
                            <p class="text-sm font-bold text-gray-800 dark:text-gray-100">Click to upload</p>
                            <p class="text-[11px] text-gray-500 mt-1">Hold CNIC next to your face</p>
                            <img id="preview_selfie_cnic" class="preview-img mx-auto hidden" alt="">
                        </label>
                    </div>
                </div>
            </div>

            <!-- ============ STEP 4: Review ============ -->
            <div class="kyc-step hidden" data-step="4">
                <div class="mb-6">
                    <h2 class="text-xl font-black flex items-center gap-3">
                        <span
                            class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center"><i
                                class="fa-solid fa-check"></i></span>
                        Review & Submit
                    </h2>
                    <p class="text-xs text-gray-500 mt-2">Verify all details before submitting.</p>
                </div>

                <div class="space-y-4">
                    <div
                        class="p-5 rounded-2xl bg-gray-50 dark:bg-slate-800/50 border border-gray-200 dark:border-gray-800">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-gray-500 mb-3">Personal Info</h3>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Name</dt>
                                <dd id="rv_name" class="font-semibold">—</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">CNIC</dt>
                                <dd id="rv_cnic" class="font-semibold font-mono">—</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">DOB</dt>
                                <dd id="rv_dob" class="font-semibold">—</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Phone</dt>
                                <dd id="rv_phone" class="font-semibold">—</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="grid grid-cols-4 gap-3">
                        <div class="p-3 rounded-xl bg-gray-50 dark:bg-slate-800/50 text-center">
                            <img id="rv_img_front" class="w-full h-20 object-cover rounded-lg hidden" alt="">
                            <p class="text-[10px] font-bold uppercase text-gray-500 mt-2">CNIC Front</p>
                        </div>
                        <div class="p-3 rounded-xl bg-gray-50 dark:bg-slate-800/50 text-center">
                            <img id="rv_img_back" class="w-full h-20 object-cover rounded-lg hidden" alt="">
                            <p class="text-[10px] font-bold uppercase text-gray-500 mt-2">CNIC Back</p>
                        </div>
                        <div class="p-3 rounded-xl bg-gray-50 dark:bg-slate-800/50 text-center">
                            <img id="rv_img_selfie" class="w-full h-20 object-cover rounded-lg hidden" alt="">
                            <p class="text-[10px] font-bold uppercase text-gray-500 mt-2">Selfie</p>
                        </div>
                        <div class="p-3 rounded-xl bg-gray-50 dark:bg-slate-800/50 text-center">
                            <img id="rv_img_selfie_cnic" class="w-full h-20 object-cover rounded-lg hidden" alt="">
                            <p class="text-[10px] font-bold uppercase text-gray-500 mt-2">Selfie + CNIC</p>
                        </div>
                    </div>

                    <label
                        class="flex items-start gap-3 p-4 rounded-xl bg-indigo-500/5 border border-indigo-500/20 cursor-pointer">
                        <input type="checkbox" id="consent" required class="mt-0.5 h-4 w-4 text-indigo-600 rounded">
                        <span class="text-xs text-gray-700 dark:text-gray-300 leading-relaxed">
                            I confirm all information provided is accurate. I understand that providing false
                            information may result in account suspension. I consent to NexusBids storing my documents
                            securely for verification purposes.
                        </span>
                    </label>
                </div>
            </div>

            <!-- Navigation -->
            <div
                class="flex items-center justify-between gap-3 mt-8 pt-6 border-t border-gray-100 dark:border-gray-800">
                <button type="button" id="btnPrev"
                    class="px-5 py-3 rounded-xl bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 text-gray-700 dark:text-gray-200 text-sm font-bold transition opacity-0 pointer-events-none">
                    <i class="fa-solid fa-arrow-left mr-1.5"></i> Previous
                </button>

                <div class="text-xs text-gray-500" id="stepLabel">Step 1 of 4</div>

                <button type="button" id="btnNext"
                    class="px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold shadow-lg shadow-indigo-600/30 transition hover:-translate-y-0.5">
                    Next <i class="fa-solid fa-arrow-right ml-1.5"></i>
                </button>

                <button type="submit" id="btnSubmit"
                    class="hidden px-6 py-3 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white text-sm font-bold shadow-lg shadow-emerald-600/30 transition hover:-translate-y-0.5">
                    <i class="fa-solid fa-paper-plane mr-1.5"></i> Submit Verification
                </button>
            </div>
        </form>

        <!-- Privacy note -->
        <div class="mt-6 flex items-start gap-3 text-xs text-gray-500 dark:text-gray-400">
            <i class="fa-solid fa-lock text-emerald-500 mt-0.5"></i>
            <p class="leading-relaxed">Your documents are stored encrypted and only accessible to authorized
                verification staff. We never share your personal data with third parties.</p>
        </div>
    </main>

    <script>
        (function () {
            // Theme
            const html = document.documentElement;
            const saved = localStorage.getItem('theme');
            if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) html.classList.add('dark');
            document.getElementById('themeToggle')?.addEventListener('click', () => {
                html.classList.toggle('dark');
                localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light');
            });

            // CNIC auto-format
            const cnicInput = document.getElementById('cnic_number');
            cnicInput?.addEventListener('input', function () {
                let v = this.value.replace(/\D/g, '').slice(0, 13);
                if (v.length > 5) v = v.slice(0, 5) + '-' + v.slice(5);
                if (v.length > 13) v = v.slice(0, 13) + '-' + v.slice(13);
                this.value = v;
            });

            // File preview
            function bindFile(inputId, previewId, boxId) {
                const input = document.getElementById(inputId);
                const preview = document.getElementById(previewId);
                const box = document.getElementById(boxId);
                input?.addEventListener('change', function () {
                    const f = this.files[0];
                    if (!f) return;
                    if (f.size > 5 * 1024 * 1024) {
                        alert('File too large (max 5MB)');
                        this.value = '';
                        return;
                    }
                    const reader = new FileReader();
                    reader.onload = e => {
                        preview.src = e.target.result;
                        preview.classList.remove('hidden');
                        box.classList.add('has-file');
                    };
                    reader.readAsDataURL(f);
                });
            }
            bindFile('cnic_front', 'preview_front', 'box_front');
            bindFile('cnic_back', 'preview_back', 'box_back');
            bindFile('selfie', 'preview_selfie', 'box_selfie');
            bindFile('selfie_with_cnic', 'preview_selfie_cnic', 'box_selfie_cnic');

            // Step logic
            let currentStep = 1;
            const totalSteps = 4;

            function showStep(n) {
                document.querySelectorAll('.kyc-step').forEach(s => {
                    s.classList.toggle('hidden', parseInt(s.dataset.step) !== n);
                });
                document.querySelectorAll('.step-indicator').forEach(s => {
                    const sn = parseInt(s.dataset.step);
                    s.classList.toggle('step-active', sn === n);
                    s.classList.toggle('step-done', sn < n);
                });

                document.getElementById('stepLabel').textContent = `Step ${n} of ${totalSteps}`;
                const prev = document.getElementById('btnPrev');
                const next = document.getElementById('btnNext');
                const submit = document.getElementById('btnSubmit');

                prev.classList.toggle('opacity-0', n === 1);
                prev.classList.toggle('pointer-events-none', n === 1);
                next.classList.toggle('hidden', n === totalSteps);
                submit.classList.toggle('hidden', n !== totalSteps);

                if (n === 4) populateReview();

                window.scrollTo({top: 0, behavior: 'smooth'});
            }

            function validateStep(n) {
                const step = document.querySelector(`.kyc-step[data-step="${n}"]`);
                if (!step) return true;
                const inputs = step.querySelectorAll('input[required], textarea[required]');
                for (const el of inputs) {
                    if (!el.checkValidity()) {
                        el.reportValidity();
                        return false;
                    }
                }
                return true;
            }

            function populateReview() {
                document.getElementById('rv_name').textContent = document.getElementById('full_name_on_cnic').value || '—';
                document.getElementById('rv_cnic').textContent = document.getElementById('cnic_number').value || '—';
                document.getElementById('rv_dob').textContent = document.getElementById('date_of_birth').value || '—';
                document.getElementById('rv_phone').textContent = document.getElementById('phone').value || '—';

                [['preview_front', 'rv_img_front'], ['preview_back', 'rv_img_back'], ['preview_selfie', 'rv_img_selfie'], ['preview_selfie_cnic', 'rv_img_selfie_cnic']].forEach(([srcId, dstId]) => {
                    const s = document.getElementById(srcId);
                    const d = document.getElementById(dstId);
                    if (s && s.src && !s.classList.contains('hidden')) {
                        d.src = s.src;
                        d.classList.remove('hidden');
                    }
                });
            }

            document.getElementById('btnNext')?.addEventListener('click', () => {
                if (!validateStep(currentStep)) return;
                if (currentStep < totalSteps) {
                    currentStep++;
                    showStep(currentStep);
                }
            });
            document.getElementById('btnPrev')?.addEventListener('click', () => {
                if (currentStep > 1) {
                    currentStep--;
                    showStep(currentStep);
                }
            });

            document.querySelectorAll('.step-indicator').forEach(el => {
                el.addEventListener('click', () => {
                    const n = parseInt(el.dataset.step);
                    if (n < currentStep) {currentStep = n; showStep(n);}
                });
            });

            // Submit check
            document.getElementById('kycForm')?.addEventListener('submit', function (e) {
                if (!document.getElementById('consent').checked) {
                    e.preventDefault();
                    alert('Please accept the consent to submit.');
                    return false;
                }
                const btn = document.getElementById('btnSubmit');
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1.5"></i> Submitting...';
            });
        })();
    </script>
    <?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>