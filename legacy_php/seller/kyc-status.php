<?php
session_start();
date_default_timezone_set('Asia/Karachi');

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "seller") {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/notifications.php";
require_once "../includes/kyc-helpers.php";

$seller_id = (int) $_SESSION['user_id'];

$u = $conn->prepare("SELECT full_name, email, avatar, seller_kyc_status, seller_kyc_level, seller_kyc_verified_at FROM users WHERE user_id = ?");
$u->bind_param("i", $seller_id);
$u->execute();
$user = $u->get_result()->fetch_assoc();
$u->close();

$full_name = $user['full_name'] ?? 'Seller';
$status_key = $user['seller_kyc_status'] ?? 'unverified';
$kyc_level = (int) ($user['seller_kyc_level'] ?? 0);
$verified_at = $user['seller_kyc_verified_at'] ?? null;
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));
$unread_notifications = nb_unread_count($conn, $seller_id);

// Latest KYC
$stmt = $conn->prepare("SELECT * FROM kyc_verifications WHERE user_id = ? AND kyc_scope = 'seller' ORDER BY kyc_id DESC LIMIT 1");
$stmt->bind_param("i", $seller_id);
$stmt->execute();
$latest = $stmt->get_result()->fetch_assoc();
$stmt->close();

$eligibility = nb_kyc_can_submit_seller($conn, $seller_id);
$meta = nb_kyc_status_meta($status_key);

$success = $_SESSION['kyc_success'] ?? '';
$error = $_SESSION['kyc_error'] ?? '';
unset($_SESSION['kyc_success'], $_SESSION['kyc_error']);

// AI data
$ai_status = $latest['ai_status'] ?? 'pending';
$ai_confidence = (int) ($latest['ai_confidence'] ?? 0);
$ai_recommendation = $latest['ai_recommendation'] ?? 'review';
$ai_flags = !empty($latest['ai_flags']) ? json_decode($latest['ai_flags'], true) : [];
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seller KYC Status · NexusBids</title>
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

        @keyframes spin-soft {
            to {
                transform: rotate(360deg);
            }
        }

        .spin-soft {
            animation: spin-soft 2s linear infinite;
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 antialiased min-h-screen">

    <header
        class="sticky top-0 z-40 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
            <a class="flex items-center gap-2.5 text-2xl font-black" href="dashboard.php">
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
                    <i class="fa-solid fa-arrow-left mr-1.5"></i> Profile
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-4xl mx-auto px-4 sm:px-8 py-8 space-y-6">

        <?php if ($success): ?>
            <div
                class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-700 dark:text-emerald-300 flex items-center gap-3">
                <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div
                class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-700 dark:text-rose-300 flex items-center gap-3">
                <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- AI PROCESSING BANNER -->
        <?php if ($latest && $status_key === 'pending' && $ai_status === 'pending'): ?>
            <div id="aiProcessingBanner"
                class="p-5 rounded-2xl bg-gradient-to-r from-violet-500/10 to-indigo-500/10 border border-violet-500/30">
                <div class="flex items-center gap-4">
                    <div
                        class="w-12 h-12 rounded-xl bg-violet-500/20 text-violet-600 dark:text-violet-400 flex items-center justify-center text-lg">
                        <i class="fa-solid fa-robot spin-soft"></i>
                    </div>
                    <div class="flex-1">
                        <div class="font-bold text-violet-900 dark:text-violet-300">AI is analyzing your documents...</div>
                        <div class="text-xs text-violet-700 dark:text-violet-400 mt-1">
                            <span id="aiProgressText">Extracting text from CNIC...</span>
                        </div>
                        <div class="mt-3 h-1.5 rounded-full bg-violet-500/20 overflow-hidden">
                            <div id="aiProgressBar" class="h-full bg-violet-500 rounded-full transition-all duration-500"
                                style="width: 10%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                (function () {
                    const kycId = <?= (int) ($latest['kyc_id'] ?? 0) ?>;
                    if (!kycId) return;

                    const bar = document.getElementById('aiProgressBar');
                    const txt = document.getElementById('aiProgressText');
                    const steps = [
                        'Extracting text from CNIC...',
                        'Verifying face match...',
                        'Validating bank details...',
                        'Calculating confidence score...',
                    ];
                    let stepIdx = 0;
                    let progress = 10;

                    const stepper = setInterval(() => {
                        progress = Math.min(90, progress + Math.random() * 12);
                        bar.style.width = progress + '%';
                        stepIdx = Math.min(steps.length - 1, Math.floor(progress / 25));
                        txt.textContent = steps[stepIdx];
                    }, 800);

                    fetch('kyc-process-ai.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'kyc_id=' + kycId + '&csrf_token=<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>'
                    })
                        .then(r => r.json())
                        .then(data => {
                            clearInterval(stepper);
                            bar.style.width = '100%';
                            txt.textContent = data.ok ? '✅ Analysis complete! Reloading...' : '⚠️ ' + (data.error || 'AI failed');
                            setTimeout(() => window.location.reload(), 1200);
                        })
                        .catch(err => {
                            clearInterval(stepper);
                            txt.textContent = '⚠️ Network error. Please refresh.';
                        });
                })();
            </script>
        <?php endif; ?>

        <!-- AI RESULT CARD -->
        <?php if ($latest && $ai_status === 'completed'): ?>
            <div
                class="bg-white dark:bg-slate-900 rounded-3xl border-2 <?= $ai_recommendation === 'approve' ? 'border-emerald-500/40' : ($ai_recommendation === 'reject' ? 'border-rose-500/40' : 'border-amber-500/40') ?> p-6">
                <div class="flex items-center justify-between gap-4 mb-4 flex-wrap">
                    <div class="flex items-center gap-3">
                        <span
                            class="w-10 h-10 rounded-xl <?= $ai_recommendation === 'approve' ? 'bg-emerald-500/10 text-emerald-600' : ($ai_recommendation === 'reject' ? 'bg-rose-500/10 text-rose-600' : 'bg-amber-500/10 text-amber-600') ?> flex items-center justify-center text-lg">
                            <i class="fa-solid fa-robot"></i>
                        </span>
                        <div>
                            <h3 class="text-sm font-bold">AI Analysis</h3>
                            <p class="text-[11px] text-gray-500">Automated verification results</p>
                        </div>
                    </div>
                    <span
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full <?= $ai_recommendation === 'approve' ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' : ($ai_recommendation === 'reject' ? 'bg-rose-500/10 text-rose-700 dark:text-rose-400' : 'bg-amber-500/10 text-amber-700 dark:text-amber-400') ?> text-[10px] font-bold uppercase tracking-wider">
                        <?= $ai_recommendation === 'approve' ? 'High Confidence' : ($ai_recommendation === 'reject' ? 'Low Confidence' : 'Needs Review') ?>
                    </span>
                </div>

                <div class="grid grid-cols-3 gap-3 mb-4">
                    <div class="text-center p-3 rounded-xl bg-gray-50 dark:bg-slate-800/50">
                        <div
                            class="text-2xl font-black tabular-nums <?= $ai_confidence >= 75 ? 'text-emerald-600' : ($ai_confidence >= 50 ? 'text-amber-600' : 'text-rose-600') ?>">
                            <?= $ai_confidence ?>%
                        </div>
                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mt-1">Overall Score</div>
                    </div>
                    <div class="text-center p-3 rounded-xl bg-gray-50 dark:bg-slate-800/50">
                        <div class="text-2xl font-black tabular-nums"><?= (int) ($latest['ai_face_match'] ?? 0) ?>%</div>
                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mt-1">Face Match</div>
                    </div>
                    <div class="text-center p-3 rounded-xl bg-gray-50 dark:bg-slate-800/50">
                        <div class="text-2xl font-black tabular-nums"><?= (int) ($latest['ai_data_match'] ?? 0) ?>%</div>
                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mt-1">Data Match</div>
                    </div>
                </div>

                <?php if (!empty($ai_flags)): ?>
                    <div
                        class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-[11px] text-amber-800 dark:text-amber-300">
                        <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                        <strong>Notes:</strong>
                        <?= htmlspecialchars(implode(', ', array_map(fn($f) => str_replace('_', ' ', $f), $ai_flags))) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Status Hero -->
        <div class="relative rounded-3xl bg-slate-950 text-white p-8 overflow-hidden shadow-2xl border border-gray-800">
            <div
                class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-violet-900/40 via-slate-950 to-slate-950">
            </div>
            <div class="relative flex items-start justify-between gap-6 flex-wrap">
                <div class="flex items-center gap-4">
                    <div
                        class="w-16 h-16 rounded-2xl <?= $meta['accent'] ?> flex items-center justify-center text-white text-2xl shadow-lg">
                        <i class="fa-solid <?= $meta['icon'] ?>"></i>
                    </div>
                    <div>
                        <div class="text-[11px] uppercase tracking-wider font-bold text-gray-400">Seller KYC Status
                        </div>
                        <h1 class="text-2xl font-black mt-1"><?= htmlspecialchars($meta['label']) ?></h1>
                        <?php if ($verified_at): ?>
                            <p class="text-xs text-gray-400 mt-1">Verified on <?= date('d M Y', strtotime($verified_at)) ?>
                            </p>
                        <?php elseif ($latest): ?>
                            <p class="text-xs text-gray-400 mt-1">Submitted
                                <?= date('d M Y, h:i A', strtotime($latest['created_at'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-[11px] uppercase tracking-wider font-bold text-gray-400">Level</div>
                    <div class="text-2xl font-black mt-1"><?= nb_kyc_kyc_level_label($kyc_level) ?></div>
                </div>
            </div>

            <?php if ($latest && $status_key === 'rejected' && !empty($latest['rejection_reason'])): ?>
                <div class="mt-6 p-4 rounded-xl bg-rose-500/10 border border-rose-500/30">
                    <div class="text-[11px] font-bold uppercase text-rose-400 mb-1"><i class="fa-solid fa-circle-xmark"></i>
                        Reason</div>
                    <p class="text-sm text-rose-200"><?= htmlspecialchars($latest['rejection_reason']) ?></p>
                </div>
            <?php endif; ?>

            <div class="mt-6 flex flex-wrap gap-3">
                <?php if (in_array($status_key, ['unverified', 'rejected'], true)): ?>
                    <?php if ($eligibility['ok']): ?>
                        <a href="kyc.php"
                            class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-violet-600 hover:bg-violet-500 text-white text-sm font-bold shadow-lg shadow-violet-600/30 transition hover:-translate-y-0.5">
                            <i class="fa-solid fa-id-card"></i>
                            <?= $status_key === 'rejected' ? 'Retry KYC' : 'Start Seller KYC' ?>
                        </a>
                    <?php else: ?>
                        <div class="px-5 py-3 rounded-xl bg-white/5 border border-white/10 text-xs text-gray-300">
                            <i class="fa-solid fa-lock text-[10px] mr-1"></i> <?= htmlspecialchars($eligibility['reason']) ?>
                        </div>
                    <?php endif; ?>
                <?php elseif ($status_key === 'pending'): ?>
                    <div
                        class="px-5 py-3 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-300 text-xs font-bold flex items-center gap-2">
                        <i class="fa-solid fa-hourglass-half"></i> Under review by our team
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </main>

    <script>
        (function () {const h = document.documentElement, s = localStorage.getItem('theme'); if (s === 'dark' || (!s && window.matchMedia('(prefers-color-scheme: dark)').matches)) h.classList.add('dark'); document.getElementById('themeToggle')?.addEventListener('click', () => {h.classList.toggle('dark'); localStorage.setItem('theme', h.classList.contains('dark') ? 'dark' : 'light');});})();
    </script>
    <?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>