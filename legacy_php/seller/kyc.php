<?php
session_start();
date_default_timezone_set('Asia/Karachi');

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "seller") {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";
require_once "../includes/kyc-helpers.php";

$seller_id = intval($_SESSION["user_id"]);

/* Eligibility check */
$eligibility = nb_kyc_can_submit_seller($conn, $seller_id);
if (!$eligibility['ok']) {
    $_SESSION['flash_error'] = $eligibility['reason'];
    header("Location: kyc-status.php");
    exit();
}

/* Load user */
$u = $conn->prepare("SELECT full_name, email, avatar, phone FROM users WHERE user_id = ? LIMIT 1");
$u->bind_param("i", $seller_id);
$u->execute();
$user = $u->get_result()->fetch_assoc();
$u->close();

$full_name = $user['full_name'] ?? 'Seller';
$email = $user['email'] ?? '';
$avatar = $user['avatar'] ?? '';
$phone = $user['phone'] ?? '';
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));
$unread_notifications = nb_unread_count($conn, $seller_id);

$error = $_SESSION['kyc_error'] ?? '';
unset($_SESSION['kyc_error']);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seller KYC · NexusBids</title>
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = {darkMode: 'class', theme: {extend: {fontFamily: {sans: ['Inter', 'system-ui', 'sans-serif']}}}};
        (function () {try {const s = localStorage.getItem('theme'); const d = window.matchMedia('(prefers-color-scheme: dark)').matches; if (s === 'dark' || (!s && d)) document.documentElement.classList.add('dark');} catch (e) { } })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', system-ui, sans-serif
        }

        .glass {
            backdrop-filter: blur(16px) saturate(180%)
        }

        .step-indicator {
            cursor: pointer;
            transition: all .2s
        }

        .step-active .step-dot {
            background: #7c3aed;
            color: #fff;
            box-shadow: 0 0 0 6px rgba(124, 58, 237, .15)
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
            transition: all .25s
        }

        .dark .step-dot {
            background: #334155;
            color: #94a3b8
        }

        .upload-box {
            border: 2px dashed #cbd5e1;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all .2s
        }

        .upload-box:hover {
            border-color: #7c3aed;
            background: rgba(124, 58, 237, .04)
        }

        .upload-box.has-file {
            border-color: #10b981;
            background: rgba(16, 185, 129, .05)
        }

        .preview-img {
            max-height: 180px;
            border-radius: 12px;
            margin-top: 12px;
            object-fit: contain
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 antialiased min-h-screen">

    <!-- Navbar -->
    <header
        class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
            <a class="flex items-center gap-2.5 text-2xl font-black shrink-0" href="../index.php">
                <span
                    class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg"><i
                        class="fa-solid fa-gavel text-lg"></i></span>
                Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
                <span class="text-xs bg-violet-500 text-white px-2 py-0.5 rounded font-black ml-1">SELLER</span>
            </a>
            <div class="flex items-center gap-2">
                <button id="themeToggle"
                    class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                    <i class="fa-solid fa-moon dark:hidden"></i><i
                        class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                </button>
                <a href="profile.php"
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
                class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-violet-500/10 border border-violet-500/20 text-violet-600 dark:text-violet-400 text-xs font-bold uppercase tracking-wider mb-4">
                <i class="fa-solid fa-shield-halved"></i> Seller Verification
            </div>
            <h1 class="text-3xl sm:text-4xl font-black tracking-tight">Complete Seller KYC</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-3 max-w-xl mx-auto">
                Verify your identity and bank account to enable payouts and unlock higher selling limits.
            </p>
        </div>

        <?php if ($error): ?>
            <div
                class="mb-6 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-700 dark:text-rose-300 flex items-center gap-3">
                <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Step indicator -->
        <div class="mb-8 bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-5">
            <div class="flex items-center justify-between gap-1">
                <?php
                $steps = [
                    ['num' => 1, 'label' => 'Identity', 'icon' => 'fa-user'],
                    ['num' => 2, 'label' => 'CNIC', 'icon' => 'fa-id-card'],
                    ['num' => 3, 'label' => 'Selfie', 'icon' => 'fa-camera'],
                    ['num' => 4, 'label' => 'Bank', 'icon' => 'fa-building-columns'],
                    ['num' => 5, 'label' => 'Review', 'icon' => 'fa-check'],
                ];
                foreach ($steps as $i => $s): ?>
                    <div class="flex items-center flex-1 <?= $i < count($steps) - 1 ? '' : 'flex-none' ?>">
                        <div class="step-indicator <?= $s['num'] === 1 ? 'step-active' : '' ?>"
                            data-step="<?= $s['num'] ?>">
                            <div class="step-dot"><i class="fa-solid <?= $s['icon'] ?> text-xs"></i></div>
                            <div
                                class="hidden sm:block text-[10px] font-bold text-gray-500 dark:text-gray-400 mt-2 text-center whitespace-nowrap">
                                <?= $s['label'] ?></div>
                        </div>
                        <?php if ($i < count($steps) - 1): ?>
                            <div class="flex-1 h-0.5 bg-gray-200 dark:bg-slate-700 mx-1 sm:mx-2"></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Form -->
        <form id="kycForm" method="POST" action="kyc-submit.php" enctype="multipart/form-data"
            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-6 sm:p-8">
            <?php nb_csrf_field(); ?>
            <input type="hidden" name="kyc_scope" value="seller">

            <!-- STEP 1: Personal Info -->
            <div class="kyc-step" data-step="1">
                <h2 class="text-xl font-black flex items-center gap-3 mb-6">
                    <span
                        class="w-10 h-10 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center"><i
                            class="fa-solid fa-user"></i></span>
                    Personal Information
                </h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Full Name (as on CNIC)
                            <span class="text-rose-500">*</span></label>
                        <input type="text" name="full_name_on_cnic" required maxlength="150"
                            value="<?= htmlspecialchars($full_name) ?>"
                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-violet-500">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">CNIC Number <span
                                class="text-rose-500">*</span></label>
                        <input type="text" name="cnic_number" id="cnic_number" required placeholder="42101-1234567-8"
                            maxlength="15" inputmode="numeric"
                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm font-mono tracking-wider focus:outline-none focus:ring-2 focus:ring-violet-500">
                        <p class="text-[11px] text-gray-400 mt-1.5">Format: 5-7-1 digits (auto-formatted)</p>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Date of Birth <span
                                class="text-rose-500">*</span></label>
                        <input type="date" name="date_of_birth" required
                            max="<?= date('Y-m-d', strtotime('-18 years')) ?>"
                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-violet-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Phone</label>
                        <input type="tel" name="phone" maxlength="20" value="<?= htmlspecialchars($phone) ?>"
                            placeholder="03XX-XXXXXXX"
                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-violet-500">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Father's Name</label>
                        <input type="text" name="father_name" maxlength="150"
                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-violet-500">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Address (as on
                            CNIC)</label>
                        <textarea name="address" rows="2" maxlength="500"
                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm resize-none focus:outline-none focus:ring-2 focus:ring-violet-500"></textarea>
                    </div>
                </div>
            </div>

            <!-- STEP 2: CNIC Photos -->
            <div class="kyc-step hidden" data-step="2">
                <h2 class="text-xl font-black flex items-center gap-3 mb-6">
                    <span
                        class="w-10 h-10 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center"><i
                            class="fa-solid fa-id-card"></i></span>
                    CNIC Photos
                </h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">CNIC Front <span
                                class="text-rose-500">*</span></label>
                        <label class="upload-box block" id="box_front">
                            <input type="file" name="cnic_front" id="cnic_front"
                                accept="image/jpeg,image/png,image/webp" required class="hidden">
                            <i class="fa-solid fa-cloud-arrow-up text-3xl text-violet-500 mb-2"></i>
                            <p class="text-sm font-bold">Click to upload</p>
                            <p class="text-[11px] text-gray-500 mt-1">Photo side</p>
                            <img id="preview_front" class="preview-img mx-auto hidden" alt="">
                        </label>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">CNIC Back <span
                                class="text-rose-500">*</span></label>
                        <label class="upload-box block" id="box_back">
                            <input type="file" name="cnic_back" id="cnic_back" accept="image/jpeg,image/png,image/webp"
                                required class="hidden">
                            <i class="fa-solid fa-cloud-arrow-up text-3xl text-violet-500 mb-2"></i>
                            <p class="text-sm font-bold">Click to upload</p>
                            <p class="text-[11px] text-gray-500 mt-1">Address side</p>
                            <img id="preview_back" class="preview-img mx-auto hidden" alt="">
                        </label>
                    </div>
                </div>
            </div>

            <!-- STEP 3: Selfie -->
            <div class="kyc-step hidden" data-step="3">
                <h2 class="text-xl font-black flex items-center gap-3 mb-6">
                    <span
                        class="w-10 h-10 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center"><i
                            class="fa-solid fa-camera"></i></span>
                    Selfie Verification
                </h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Selfie <span
                                class="text-rose-500">*</span></label>
                        <label class="upload-box block" id="box_selfie">
                            <input type="file" name="selfie" id="selfie" accept="image/jpeg,image/png,image/webp"
                                required class="hidden">
                            <i class="fa-solid fa-face-smile text-3xl text-violet-500 mb-2"></i>
                            <p class="text-sm font-bold">Click to upload</p>
                            <img id="preview_selfie" class="preview-img mx-auto hidden" alt="">
                        </label>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Selfie with CNIC <span
                                class="text-rose-500">*</span></label>
                        <label class="upload-box block" id="box_selfie_cnic">
                            <input type="file" name="selfie_with_cnic" id="selfie_with_cnic"
                                accept="image/jpeg,image/png,image/webp" required class="hidden">
                            <i class="fa-solid fa-id-badge text-3xl text-violet-500 mb-2"></i>
                            <p class="text-sm font-bold">Click to upload</p>
                            <img id="preview_selfie_cnic" class="preview-img mx-auto hidden" alt="">
                        </label>
                    </div>
                </div>
            </div>

            <!-- STEP 4: Bank Details -->
            <div class="kyc-step hidden" data-step="4">
                <h2 class="text-xl font-black flex items-center gap-3 mb-6">
                    <span
                        class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center"><i
                            class="fa-solid fa-building-columns"></i></span>
                    Bank Account Details
                </h2>
                <div
                    class="p-4 rounded-xl bg-emerald-500/5 border border-emerald-500/20 mb-5 text-xs text-emerald-800 dark:text-emerald-300 leading-relaxed">
                    <i class="fa-solid fa-circle-info mr-1"></i>
                    Ye information sirf aapki payout ke liye use hogi. Double-check karein ke naam aur account number
                    sahi hain.
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Seller Type <span
                                class="text-rose-500">*</span></label>
                        <select name="seller_type" id="seller_type" required
                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-violet-500">
                            <option value="individual">Individual Seller</option>
                            <option value="business">Business Seller</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Bank Name <span
                                class="text-rose-500">*</span></label>
                        <input type="text" name="bank_name" required maxlength="100" placeholder="e.g. HBL, Meezan, UBL"
                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-violet-500">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Account Holder Name <span
                                class="text-rose-500">*</span></label>
                        <input type="text" name="bank_account_holder" required maxlength="150"
                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-violet-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Account Number <span
                                class="text-rose-500">*</span></label>
                        <input type="text" name="bank_account_number" required maxlength="50" inputmode="numeric"
                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm font-mono focus:outline-none focus:ring-2 focus:ring-violet-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">IBAN (optional)</label>
                        <input type="text" name="bank_iban" maxlength="50" placeholder="PK36SCBL0000001123456702"
                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm font-mono focus:outline-none focus:ring-2 focus:ring-violet-500">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Branch Name</label>
                        <input type="text" name="bank_branch" maxlength="100"
                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-violet-500">
                    </div>

                    <!-- Business fields (hidden by default) -->
                    <div id="businessFields"
                        class="sm:col-span-2 hidden grid grid-cols-1 sm:grid-cols-2 gap-5 pt-4 border-t border-gray-100 dark:border-gray-800">
                        <div class="sm:col-span-2">
                            <div class="text-xs font-bold uppercase tracking-wider text-gray-500 mb-3">
                                <i class="fa-solid fa-briefcase text-amber-500"></i> Business Information
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider mb-2">Business Name</label>
                            <input type="text" name="business_name" maxlength="150"
                                class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-violet-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider mb-2">Business Type</label>
                            <select name="business_type"
                                class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-violet-500">
                                <option value="">Select...</option>
                                <option value="sole_proprietor">Sole Proprietor</option>
                                <option value="partnership">Partnership</option>
                                <option value="pvt_ltd">Pvt Ltd</option>
                                <option value="ltd">Ltd</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider mb-2">Registration #</label>
                            <input type="text" name="business_reg_number" maxlength="100"
                                class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-violet-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider mb-2">NTN Number</label>
                            <input type="text" name="ntn_number" maxlength="50"
                                class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-violet-500">
                        </div>
                    </div>
                </div>
            </div>

            <!-- STEP 5: Review -->
            <div class="kyc-step hidden" data-step="5">
                <h2 class="text-xl font-black flex items-center gap-3 mb-6">
                    <span
                        class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center"><i
                            class="fa-solid fa-check"></i></span>
                    Review & Submit
                </h2>
                <div class="space-y-4">
                    <div
                        class="p-5 rounded-2xl bg-gray-50 dark:bg-slate-800/50 border border-gray-200 dark:border-gray-800">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-gray-500 mb-3">Summary</h3>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Name</dt>
                                <dd id="rv_name" class="font-semibold">—</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">CNIC</dt>
                                <dd id="rv_cnic" class="font-semibold font-mono text-xs">—</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Bank</dt>
                                <dd id="rv_bank" class="font-semibold">—</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Account</dt>
                                <dd id="rv_account" class="font-semibold font-mono text-xs">—</dd>
                            </div>
                        </dl>
                    </div>

                    <label
                        class="flex items-start gap-3 p-4 rounded-xl bg-violet-500/5 border border-violet-500/20 cursor-pointer">
                        <input type="checkbox" id="consent" required class="mt-0.5 h-4 w-4 text-violet-600 rounded">
                        <span class="text-xs text-gray-700 dark:text-gray-300 leading-relaxed">
                            I confirm all information is accurate. I understand providing false information may result
                            in account suspension. I consent to NexusBids securely storing and verifying my documents.
                        </span>
                    </label>
                </div>
            </div>

            <!-- Nav buttons -->
            <div
                class="flex items-center justify-between gap-3 mt-8 pt-6 border-t border-gray-100 dark:border-gray-800">
                <button type="button" id="btnPrev"
                    class="px-5 py-3 rounded-xl bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 text-sm font-bold opacity-0 pointer-events-none transition">
                    <i class="fa-solid fa-arrow-left mr-1.5"></i> Previous
                </button>
                <div class="text-xs text-gray-500" id="stepLabel">Step 1 of 5</div>
                <button type="button" id="btnNext"
                    class="px-6 py-3 rounded-xl bg-violet-600 hover:bg-violet-500 text-white text-sm font-bold shadow-lg shadow-violet-600/30 transition hover:-translate-y-0.5">
                    Next <i class="fa-solid fa-arrow-right ml-1.5"></i>
                </button>
                <button type="submit" id="btnSubmit"
                    class="hidden px-6 py-3 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white text-sm font-bold shadow-lg shadow-emerald-600/30 transition hover:-translate-y-0.5">
                    <i class="fa-solid fa-paper-plane mr-1.5"></i> Submit KYC
                </button>
            </div>
        </form>

        <div class="mt-6 flex items-start gap-3 text-xs text-gray-500 dark:text-gray-400">
            <i class="fa-solid fa-lock text-emerald-500 mt-0.5"></i>
            <p>Your documents are encrypted and accessible only to authorized verification staff.</p>
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
                    if (f.size > 5 * 1024 * 1024) {alert('File too large (max 5MB)'); this.value = ''; return;}
                    const r = new FileReader();
                    r.onload = e => {preview.src = e.target.result; preview.classList.remove('hidden'); box.classList.add('has-file');};
                    r.readAsDataURL(f);
                });
            }
            bindFile('cnic_front', 'preview_front', 'box_front');
            bindFile('cnic_back', 'preview_back', 'box_back');
            bindFile('selfie', 'preview_selfie', 'box_selfie');
            bindFile('selfie_with_cnic', 'preview_selfie_cnic', 'box_selfie_cnic');

            // Business fields toggle
            document.getElementById('seller_type')?.addEventListener('change', function () {
                document.getElementById('businessFields').classList.toggle('hidden', this.value !== 'business');
            });

            // Steps
            let currentStep = 1;
            const totalSteps = 5;

            function showStep(n) {
                document.querySelectorAll('.kyc-step').forEach(s => s.classList.toggle('hidden', parseInt(s.dataset.step) !== n));
                document.querySelectorAll('.step-indicator').forEach(s => {
                    const sn = parseInt(s.dataset.step);
                    s.classList.toggle('step-active', sn === n);
                    s.classList.toggle('step-done', sn < n);
                });
                document.getElementById('stepLabel').textContent = `Step ${n} of ${totalSteps}`;
                const prev = document.getElementById('btnPrev'), next = document.getElementById('btnNext'), sub = document.getElementById('btnSubmit');
                prev.classList.toggle('opacity-0', n === 1);
                prev.classList.toggle('pointer-events-none', n === 1);
                next.classList.toggle('hidden', n === totalSteps);
                sub.classList.toggle('hidden', n !== totalSteps);
                if (n === totalSteps) populateReview();
                window.scrollTo({top: 0, behavior: 'smooth'});
            }

            function validateStep(n) {
                const step = document.querySelector(`.kyc-step[data-step="${n}"]`);
                if (!step) return true;
                for (const el of step.querySelectorAll('input[required], textarea[required], select[required]')) {
                    if (!el.checkValidity()) {el.reportValidity(); return false;}
                }
                return true;
            }

            function populateReview() {
                document.getElementById('rv_name').textContent = document.querySelector('[name=full_name_on_cnic]').value || '—';
                document.getElementById('rv_cnic').textContent = document.querySelector('[name=cnic_number]').value || '—';
                document.getElementById('rv_bank').textContent = document.querySelector('[name=bank_name]').value || '—';
                const acc = document.querySelector('[name=bank_account_number]').value || '';
                document.getElementById('rv_account').textContent = acc ? '••••' + acc.slice(-4) : '—';
            }

            document.getElementById('btnNext')?.addEventListener('click', () => {if (validateStep(currentStep) && currentStep < totalSteps) {currentStep++; showStep(currentStep);} });
            document.getElementById('btnPrev')?.addEventListener('click', () => {if (currentStep > 1) {currentStep--; showStep(currentStep);} });
            document.querySelectorAll('.step-indicator').forEach(el => el.addEventListener('click', () => {const n = parseInt(el.dataset.step); if (n < currentStep) {currentStep = n; showStep(n);} }));

            document.getElementById('kycForm')?.addEventListener('submit', function (e) {
                if (!document.getElementById('consent').checked) {e.preventDefault(); alert('Please accept consent.'); return false;}
                const btn = document.getElementById('btnSubmit');
                btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1.5"></i> Submitting...';
            });
        })();
    </script>
    <?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>