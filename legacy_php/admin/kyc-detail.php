<?php
session_start();
date_default_timezone_set('Asia/Karachi');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";
require_once "../includes/kyc-helpers.php";

$admin_id = (int) $_SESSION['user_id'];
$kyc_id = (int) ($_GET['id'] ?? 0);

if ($kyc_id <= 0) {
    header("Location: kyc-queue.php");
    exit();
}

/* Fetch KYC record */
$stmt = $conn->prepare("
    SELECT k.*, u.full_name AS user_name, u.email AS user_email,
           u.kyc_status AS user_kyc_status, u.kyc_level,
           a.full_name AS reviewer_name
    FROM kyc_verifications k
    INNER JOIN users u ON u.user_id = k.user_id
    LEFT JOIN users a ON a.user_id = k.admin_id
    WHERE k.kyc_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $kyc_id);
$stmt->execute();
$kyc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$kyc) {
    $_SESSION['flash_error'] = 'KYC record not found.';
    header("Location: kyc-queue.php");
    exit();
}

/* Mark as under_review if pending */
if ($kyc['status'] === 'pending') {
    $upd = $conn->prepare("UPDATE kyc_verifications SET status = 'under_review' WHERE kyc_id = ?");
    $upd->bind_param("i", $kyc_id);
    $upd->execute();
    $upd->close();
    $kyc['status'] = 'under_review';
    nb_kyc_audit($conn, $kyc_id, (int) $kyc['user_id'], 'viewed', $admin_id, 'admin');
}

$meta = nb_kyc_status_meta($kyc['status']);
$user_initials = strtoupper(substr(preg_replace('/\s+/', '', $kyc['user_name']), 0, 2));
$cnic_plain = nb_kyc_decrypt($kyc['cnic_number_encrypted']);
$kyc_scope = $kyc['kyc_scope'] ?? 'buyer';

/* AI data */
$ai_status = $kyc['ai_status'] ?? 'pending';
$ai_score = (int) ($kyc['ai_confidence'] ?? 0);
$ai_rec = $kyc['ai_recommendation'] ?? 'review';
$ai_face = (int) ($kyc['ai_face_match'] ?? 0);
$ai_ocr = (int) ($kyc['ai_ocr_quality'] ?? 0);
$ai_data = (int) ($kyc['ai_data_match'] ?? 0);
$ai_flags = !empty($kyc['ai_flags']) ? json_decode($kyc['ai_flags'], true) : [];
$ai_extracted = !empty($kyc['ai_extracted_data']) ? json_decode($kyc['ai_extracted_data'], true) : [];
$ai_processed_at = $kyc['ai_processed_at'] ?? null;
$ai_notes = $kyc['ai_notes'] ?? '';

/* Image proxy URL helper */
function kyc_img_url($path)
{
    if (empty($path))
        return '';
    $parts = explode('/', $path, 2);
    if (count($parts) !== 2)
        return '';
    return 'kyc-file.php?u=' . (int) $parts[0] . '&f=' . rawurlencode($parts[1]);
}

$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KYC Review · Admin · NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = {darkMode: 'class', theme: {extend: {fontFamily: {sans: ['Inter', 'system-ui', 'sans-serif']}}}};
        (function () {try {const s = localStorage.getItem('theme'); const d = window.matchMedia('(prefers-color-scheme: dark)').matches; if (s === 'dark' || (!s && d)) document.documentElement.classList.add('dark');} catch (e) { } })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        .glass {
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%)
        }

        .img-tile {
            cursor: zoom-in;
            transition: transform .2s ease
        }

        .img-tile:hover {
            transform: scale(1.02)
        }

        .lightbox {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .92);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 20px
        }

        .lightbox.active {
            display: flex
        }

        .lightbox img {
            max-width: 95vw;
            max-height: 90vh;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .6)
        }

        .score-bar {
            transition: width .8s ease-out
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 font-sans antialiased min-h-screen">

    <!-- Header -->
    <header
        class="sticky top-0 z-40 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
            <a class="flex items-center gap-2.5 text-xl font-black" href="../index.php">
                <span
                    class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white"><i
                        class="fa-solid fa-gavel"></i></span>
                Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
                <span class="text-xs bg-rose-500 text-white px-2 py-0.5 rounded font-black ml-1">ADMIN</span>
            </a>
            <div class="flex items-center gap-2">
                <button id="themeToggle"
                    class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                    <i class="fa-solid fa-moon dark:hidden"></i><i
                        class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                </button>
                <a href="kyc-queue.php"
                    class="inline-flex px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:text-indigo-600 transition">
                    <i class="fa-solid fa-arrow-left mr-1.5"></i> Back to Queue
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-8 py-8 space-y-6">

        <?php if ($flash_success): ?>
            <div
                class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-700 dark:text-emerald-300 flex items-center gap-3">
                <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($flash_success) ?>
            </div>
        <?php endif; ?>
        <?php if ($flash_error): ?>
            <div
                class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-700 dark:text-rose-300 flex items-center gap-3">
                <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($flash_error) ?>
            </div>
        <?php endif; ?>

        <!-- Header card -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-6 shadow-sm">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div
                        class="w-16 h-16 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-black text-xl flex items-center justify-center shadow-lg">
                        <?= htmlspecialchars($user_initials) ?>
                    </div>
                    <div>
                        <h1 class="text-xl font-black"><?= htmlspecialchars($kyc['user_name']) ?></h1>
                        <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($kyc['user_email']) ?></p>
                        <div class="flex items-center gap-2 mt-2 flex-wrap">
                            <span
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?= $kyc_scope === 'seller' ? 'bg-violet-500/10 text-violet-600 dark:text-violet-400 border-violet-500/20' : 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20' ?> text-[11px] font-bold uppercase tracking-wider border">
                                <i
                                    class="fa-solid <?= $kyc_scope === 'seller' ? 'fa-store' : 'fa-user' ?> text-[9px]"></i>
                                <?= $kyc_scope === 'seller' ? 'SELLER' : 'BUYER' ?>
                            </span>
                            <span
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?= $meta['bg'] ?> <?= $meta['text'] ?> text-[11px] font-bold border <?= $meta['border'] ?>">
                                <i class="fa-solid <?= $meta['icon'] ?> text-[9px]"></i> <?= $meta['label'] ?>
                            </span>
                            <span class="text-[11px] text-gray-500 font-mono">KYC
                                #<?= str_pad($kyc['kyc_id'], 6, '0', STR_PAD_LEFT) ?></span>
                        </div>
                    </div>
                </div>
                <div class="text-right text-xs text-gray-500">
                    <div>Submitted: <strong
                            class="text-gray-700 dark:text-gray-200"><?= date('d M Y, h:i A', strtotime($kyc['created_at'])) ?></strong>
                    </div>
                    <div class="mt-1">User ID: <strong
                            class="text-gray-700 dark:text-gray-200 font-mono">#<?= (int) $kyc['user_id'] ?></strong>
                    </div>
                    <?php if (!empty($kyc['reviewed_at'])): ?>
                        <div class="mt-1">Reviewed: <strong
                                class="text-gray-700 dark:text-gray-200"><?= date('d M Y', strtotime($kyc['reviewed_at'])) ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ★ AI ANALYSIS PANEL -->
        <div
            class="bg-white dark:bg-slate-900 rounded-3xl border-2 <?= $ai_rec === 'approve' ? 'border-emerald-500/40' : ($ai_rec === 'reject' ? 'border-rose-500/40' : 'border-amber-500/40') ?> p-6">
            <div class="flex items-center justify-between gap-3 mb-5 flex-wrap">
                <div class="flex items-center gap-3">
                    <span
                        class="w-12 h-12 rounded-2xl bg-gradient-to-br from-violet-500 to-indigo-500 text-white flex items-center justify-center text-lg shadow-lg">
                        <i class="fa-solid fa-robot"></i>
                    </span>
                    <div>
                        <h2 class="text-base font-bold flex items-center gap-2">
                            AI Analysis
                            <?php if ($ai_status === 'completed'): ?>
                                <span
                                    class="text-[10px] bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 px-2 py-0.5 rounded font-bold uppercase tracking-wider">Auto</span>
                            <?php endif; ?>
                        </h2>
                        <p class="text-xs text-gray-500 mt-0.5">
                            <?php if ($ai_status === 'completed'): ?>
                                Processed <?= $ai_processed_at ? date('d M Y, h:i A', strtotime($ai_processed_at)) : '' ?>
                                · Model: <?= htmlspecialchars($kyc['ai_model'] ?? 'N/A') ?>
                            <?php elseif ($ai_status === 'processing'): ?>
                                <span class="text-indigo-600 dark:text-indigo-400"><i
                                        class="fa-solid fa-spinner fa-spin"></i> Processing now...</span>
                            <?php elseif ($ai_status === 'failed'): ?>
                                <span class="text-rose-600 dark:text-rose-400"><i class="fa-solid fa-circle-xmark"></i> AI
                                    processing failed</span>
                            <?php else: ?>
                                <span class="text-gray-400">Not yet processed</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <?php if ($ai_status === 'completed'): ?>
                    <span
                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl <?= $ai_rec === 'approve' ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/30' : ($ai_rec === 'reject' ? 'bg-rose-500/10 text-rose-700 dark:text-rose-400 border border-rose-500/30' : 'bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-500/30') ?> text-xs font-bold uppercase tracking-wider">
                        <i
                            class="fa-solid <?= $ai_rec === 'approve' ? 'fa-circle-check' : ($ai_rec === 'reject' ? 'fa-circle-xmark' : 'fa-triangle-exclamation') ?>"></i>
                        <?= $ai_rec === 'approve' ? 'AI Recommends: Approve' : ($ai_rec === 'reject' ? 'AI Recommends: Reject' : 'AI Recommends: Review') ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($ai_status === 'completed'): ?>
                <!-- Score cards -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
                    <div
                        class="p-4 rounded-2xl bg-gray-50 dark:bg-slate-800/50 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Overall</span>
                            <i class="fa-solid fa-star text-amber-500 text-xs"></i>
                        </div>
                        <div
                            class="text-3xl font-black tabular-nums <?= $ai_score >= 75 ? 'text-emerald-600 dark:text-emerald-400' : ($ai_score >= 50 ? 'text-amber-600 dark:text-amber-400' : 'text-rose-600 dark:text-rose-400') ?>">
                            <?= $ai_score ?>%
                        </div>
                        <div class="mt-2 h-1.5 rounded-full bg-gray-200 dark:bg-slate-700 overflow-hidden">
                            <div class="h-full rounded-full score-bar <?= $ai_score >= 75 ? 'bg-emerald-500' : ($ai_score >= 50 ? 'bg-amber-500' : 'bg-rose-500') ?>"
                                style="width: <?= $ai_score ?>%"></div>
                        </div>
                    </div>

                    <div
                        class="p-4 rounded-2xl bg-gray-50 dark:bg-slate-800/50 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Face Match</span>
                            <i class="fa-solid fa-face-smile text-indigo-500 text-xs"></i>
                        </div>
                        <div
                            class="text-3xl font-black tabular-nums <?= $ai_face >= 70 ? 'text-emerald-600 dark:text-emerald-400' : ($ai_face >= 50 ? 'text-amber-600 dark:text-amber-400' : 'text-rose-600 dark:text-rose-400') ?>">
                            <?= $ai_face ?>%
                        </div>
                        <div class="mt-2 h-1.5 rounded-full bg-gray-200 dark:bg-slate-700 overflow-hidden">
                            <div class="h-full rounded-full score-bar <?= $ai_face >= 70 ? 'bg-emerald-500' : ($ai_face >= 50 ? 'bg-amber-500' : 'bg-rose-500') ?>"
                                style="width: <?= $ai_face ?>%"></div>
                        </div>
                    </div>

                    <div
                        class="p-4 rounded-2xl bg-gray-50 dark:bg-slate-800/50 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">OCR Quality</span>
                            <i class="fa-solid fa-eye text-violet-500 text-xs"></i>
                        </div>
                        <div
                            class="text-3xl font-black tabular-nums <?= $ai_ocr >= 70 ? 'text-emerald-600 dark:text-emerald-400' : ($ai_ocr >= 50 ? 'text-amber-600 dark:text-amber-400' : 'text-rose-600 dark:text-rose-400') ?>">
                            <?= $ai_ocr ?>%
                        </div>
                        <div class="mt-2 h-1.5 rounded-full bg-gray-200 dark:bg-slate-700 overflow-hidden">
                            <div class="h-full rounded-full score-bar <?= $ai_ocr >= 70 ? 'bg-emerald-500' : ($ai_ocr >= 50 ? 'bg-amber-500' : 'bg-rose-500') ?>"
                                style="width: <?= $ai_ocr ?>%"></div>
                        </div>
                    </div>

                    <div
                        class="p-4 rounded-2xl bg-gray-50 dark:bg-slate-800/50 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Data Match</span>
                            <i class="fa-solid fa-file-check text-emerald-500 text-xs"></i>
                        </div>
                        <div
                            class="text-3xl font-black tabular-nums <?= $ai_data >= 70 ? 'text-emerald-600 dark:text-emerald-400' : ($ai_data >= 50 ? 'text-amber-600 dark:text-amber-400' : 'text-rose-600 dark:text-rose-400') ?>">
                            <?= $ai_data ?>%
                        </div>
                        <div class="mt-2 h-1.5 rounded-full bg-gray-200 dark:bg-slate-700 overflow-hidden">
                            <div class="h-full rounded-full score-bar <?= $ai_data >= 70 ? 'bg-emerald-500' : ($ai_data >= 50 ? 'bg-amber-500' : 'bg-rose-500') ?>"
                                style="width: <?= $ai_data ?>%"></div>
                        </div>
                    </div>
                </div>

                <!-- Extracted data -->
                <?php if (!empty($ai_extracted)): ?>
                    <details class="mb-4" open>
                        <summary
                            class="cursor-pointer text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:underline flex items-center gap-2">
                            <i class="fa-solid fa-list-check"></i> AI Extracted Data from CNIC
                        </summary>
                        <div class="mt-3 p-4 rounded-2xl bg-indigo-500/5 border border-indigo-500/20">
                            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                <?php foreach ($ai_extracted as $k => $v):
                                    if (is_array($v))
                                        continue;
                                    if ($v === '' || $v === null)
                                        continue;
                                    ?>
                                    <div class="flex justify-between gap-3 py-1.5 border-b border-indigo-500/10">
                                        <dt class="text-gray-500 capitalize font-medium">
                                            <?= htmlspecialchars(str_replace('_', ' ', $k)) ?>:</dt>
                                        <dd class="font-bold text-right text-gray-900 dark:text-white">
                                            <?= htmlspecialchars((string) $v) ?></dd>
                                    </div>
                                <?php endforeach; ?>
                            </dl>
                        </div>
                    </details>
                <?php endif; ?>

                <!-- Flags -->
                <?php if (!empty($ai_flags)): ?>
                    <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/20">
                        <div class="flex items-start gap-2">
                            <i class="fa-solid fa-triangle-exclamation text-amber-500 mt-0.5"></i>
                            <div class="text-xs text-amber-800 dark:text-amber-300">
                                <strong>AI Flags:</strong>
                                <div class="flex flex-wrap gap-1.5 mt-1.5">
                                    <?php foreach ($ai_flags as $flag): ?>
                                        <span
                                            class="px-2 py-0.5 rounded bg-amber-500/20 text-[10px] font-bold uppercase tracking-wider">
                                            <?= htmlspecialchars(str_replace('_', ' ', $flag)) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="p-3 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-center gap-2">
                        <i class="fa-solid fa-circle-check text-emerald-500"></i>
                        <span class="text-xs text-emerald-800 dark:text-emerald-300 font-bold">No issues detected by AI</span>
                    </div>
                <?php endif; ?>

                <!-- Info banner -->
                <div class="mt-4 p-3 rounded-xl bg-blue-500/10 border border-blue-500/20 flex items-start gap-2">
                    <i class="fa-solid fa-circle-info text-blue-500 text-xs mt-0.5"></i>
                    <p class="text-[11px] text-blue-800 dark:text-blue-300 leading-relaxed">
                        <strong>Note:</strong> AI analysis is advisory only. Please manually verify all documents before
                        making the final decision. Your decision overrides AI.
                    </p>
                </div>

            <?php elseif ($ai_status === 'failed'): ?>
                <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/20 mb-4">
                    <div class="text-xs font-bold text-rose-700 dark:text-rose-400 mb-1">
                        <i class="fa-solid fa-circle-exclamation mr-1"></i> AI Processing Failed
                    </div>
                    <p class="text-[11px] text-rose-600 dark:text-rose-300 font-mono">
                        <?= htmlspecialchars($ai_notes ?: 'Unknown error') ?></p>
                </div>

                <div class="text-center py-4">
                    <form method="POST" action="kyc-reprocess-ai.php" class="inline">
                        <?php nb_csrf_field(); ?>
                        <input type="hidden" name="kyc_id" value="<?= (int) $kyc['kyc_id'] ?>">
                        <button type="submit"
                            class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-violet-600 hover:bg-violet-500 text-white text-sm font-bold shadow-lg shadow-violet-600/30 transition hover:-translate-y-0.5">
                            <i class="fa-solid fa-rotate"></i> Retry AI Analysis
                        </button>
                    </form>
                </div>

            <?php else: ?>
                <div class="text-center py-6">
                    <div
                        class="w-14 h-14 mx-auto rounded-2xl bg-violet-500/10 text-violet-500 flex items-center justify-center text-xl mb-3">
                        <i class="fa-solid fa-robot"></i>
                    </div>
                    <p class="text-xs text-gray-500 mb-4">AI has not analyzed this KYC yet.</p>
                    <form method="POST" action="kyc-reprocess-ai.php" class="inline">
                        <?php nb_csrf_field(); ?>
                        <input type="hidden" name="kyc_id" value="<?= (int) $kyc['kyc_id'] ?>">
                        <button type="submit"
                            class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-violet-600 hover:bg-violet-500 text-white text-sm font-bold shadow-lg shadow-violet-600/30 transition hover:-translate-y-0.5">
                            <i class="fa-solid fa-robot"></i> Run AI Analysis
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

            <!-- LEFT COLUMN -->
            <div class="lg:col-span-5 space-y-6">

                <!-- Personal Info -->
                <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-6">
                    <h2 class="text-sm font-bold mb-4 flex items-center gap-2">
                        <i class="fa-solid fa-user text-indigo-500"></i> Personal Information
                    </h2>
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between gap-4 py-2 border-b border-gray-100 dark:border-gray-800">
                            <dt class="text-gray-500">Full Name (CNIC)</dt>
                            <dd class="font-bold text-right"><?= htmlspecialchars($kyc['full_name_on_cnic']) ?></dd>
                        </div>
                        <div class="flex justify-between gap-4 py-2 border-b border-gray-100 dark:border-gray-800">
                            <dt class="text-gray-500">CNIC Number</dt>
                            <dd class="font-mono font-bold text-right text-xs">
                                <?php if ($cnic_plain !== ''): ?>
                                    <span class="cursor-pointer select-all"
                                        title="Click to select"><?= htmlspecialchars($cnic_plain) ?></span>
                                <?php else: ?>
                                    <?= htmlspecialchars($kyc['cnic_number_masked']) ?>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 py-2 border-b border-gray-100 dark:border-gray-800">
                            <dt class="text-gray-500">Father's Name</dt>
                            <dd class="font-semibold text-right"><?= htmlspecialchars($kyc['father_name'] ?: '—') ?>
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 py-2 border-b border-gray-100 dark:border-gray-800">
                            <dt class="text-gray-500">Date of Birth</dt>
                            <dd class="font-semibold text-right">
                                <?= date('d M Y', strtotime($kyc['date_of_birth'])) ?>
                                (<?= nb_kyc_calculate_age($kyc['date_of_birth']) ?>y)
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 py-2 border-b border-gray-100 dark:border-gray-800">
                            <dt class="text-gray-500">Phone</dt>
                            <dd class="font-semibold text-right"><?= htmlspecialchars($kyc['phone'] ?: '—') ?></dd>
                        </div>
                        <div class="py-2">
                            <dt class="text-gray-500 mb-1.5">Address</dt>
                            <dd class="text-sm leading-relaxed"><?= nl2br(htmlspecialchars($kyc['address'] ?: '—')) ?>
                            </dd>
                        </div>
                    </dl>
                </div>

                <?php if ($kyc_scope === 'seller'): ?>
                    <!-- Bank Details -->
                    <div class="bg-white dark:bg-slate-900 rounded-3xl border-2 border-emerald-500/30 p-6">
                        <h2 class="text-sm font-bold mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-building-columns text-emerald-500"></i> Bank Account
                            <span
                                class="text-[10px] bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 px-2 py-0.5 rounded font-bold uppercase tracking-wider">Confidential</span>
                        </h2>
                        <dl class="space-y-3 text-sm">
                            <div class="flex justify-between gap-4 py-2 border-b border-gray-100 dark:border-gray-800">
                                <dt class="text-gray-500">Bank Name</dt>
                                <dd class="font-bold text-right"><?= htmlspecialchars($kyc['bank_name'] ?: '—') ?></dd>
                            </div>
                            <div class="flex justify-between gap-4 py-2 border-b border-gray-100 dark:border-gray-800">
                                <dt class="text-gray-500">Account Holder</dt>
                                <dd class="font-bold text-right"><?= htmlspecialchars($kyc['bank_account_holder'] ?: '—') ?>
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4 py-2 border-b border-gray-100 dark:border-gray-800">
                                <dt class="text-gray-500">Account #</dt>
                                <dd class="font-mono font-bold text-right text-xs">
                                    <?= htmlspecialchars($kyc['bank_account_number'] ?: '—') ?></dd>
                            </div>
                            <?php if (!empty($kyc['bank_iban'])): ?>
                                <div class="flex justify-between gap-4 py-2 border-b border-gray-100 dark:border-gray-800">
                                    <dt class="text-gray-500">IBAN</dt>
                                    <dd class="font-mono font-bold text-right text-xs">
                                        <?= htmlspecialchars($kyc['bank_iban']) ?></dd>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($kyc['bank_branch'])): ?>
                                <div class="flex justify-between gap-4 py-2">
                                    <dt class="text-gray-500">Branch</dt>
                                    <dd class="font-bold text-right"><?= htmlspecialchars($kyc['bank_branch']) ?></dd>
                                </div>
                            <?php endif; ?>
                        </dl>
                    </div>

                    <?php if (($kyc['seller_type'] ?? '') === 'business' && !empty($kyc['business_name'])): ?>
                        <!-- Business Info -->
                        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-6">
                            <h2 class="text-sm font-bold mb-4 flex items-center gap-2">
                                <i class="fa-solid fa-briefcase text-amber-500"></i> Business Information
                            </h2>
                            <dl class="space-y-2 text-xs">
                                <div class="flex justify-between py-2 border-b border-gray-100 dark:border-gray-800">
                                    <dt class="text-gray-500">Business Name</dt>
                                    <dd class="font-bold"><?= htmlspecialchars($kyc['business_name']) ?></dd>
                                </div>
                                <div class="flex justify-between py-2 border-b border-gray-100 dark:border-gray-800">
                                    <dt class="text-gray-500">Type</dt>
                                    <dd class="font-bold capitalize">
                                        <?= htmlspecialchars(str_replace('_', ' ', $kyc['business_type'] ?? '—')) ?></dd>
                                </div>
                                <div class="flex justify-between py-2 border-b border-gray-100 dark:border-gray-800">
                                    <dt class="text-gray-500">Reg #</dt>
                                    <dd class="font-mono"><?= htmlspecialchars($kyc['business_reg_number'] ?? '—') ?></dd>
                                </div>
                                <div class="flex justify-between py-2">
                                    <dt class="text-gray-500">NTN</dt>
                                    <dd class="font-mono"><?= htmlspecialchars($kyc['ntn_number'] ?? '—') ?></dd>
                                </div>
                            </dl>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Metadata -->
                <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-5">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-gray-500 mb-3">Submission Metadata</h3>
                    <dl class="space-y-2 text-xs">
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500">IP Address</dt>
                            <dd class="font-mono"><?= htmlspecialchars($kyc['ip_address'] ?: '—') ?></dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500 shrink-0">User Agent</dt>
                            <dd class="font-mono truncate max-w-48 text-right"
                                title="<?= htmlspecialchars($kyc['user_agent'] ?? '') ?>">
                                <?= htmlspecialchars(substr($kyc['user_agent'] ?? '', 0, 40)) ?>…
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- RIGHT COLUMN -->
            <div class="lg:col-span-7 space-y-6">

                <!-- Documents -->
                <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-6">
                    <h2 class="text-sm font-bold mb-4 flex items-center gap-2">
                        <i class="fa-solid fa-images text-indigo-500"></i> Uploaded Documents
                    </h2>
                    <div class="grid grid-cols-2 gap-4">
                        <?php
                        $docs = [
                            'CNIC Front' => $kyc['cnic_front_path'],
                            'CNIC Back' => $kyc['cnic_back_path'],
                            'Selfie' => $kyc['selfie_path'],
                            'Selfie with CNIC' => $kyc['selfie_with_cnic_path'],
                        ];
                        foreach ($docs as $label => $path):
                            $url = kyc_img_url($path);
                            ?>
                            <div
                                class="rounded-2xl overflow-hidden border border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-slate-800">
                                <div class="h-48 bg-gray-100 dark:bg-slate-700 relative">
                                    <?php if ($url): ?>
                                        <img src="<?= htmlspecialchars($url) ?>" alt="<?= htmlspecialchars($label) ?>"
                                            class="w-full h-full object-contain img-tile" onclick="openLightbox(this.src)">
                                    <?php else: ?>
                                        <div class="w-full h-full flex items-center justify-center text-gray-400">
                                            <i class="fa-solid fa-image text-3xl"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="p-3 text-center text-[11px] font-bold uppercase tracking-wider text-gray-500">
                                    <?= htmlspecialchars($label) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Action Form -->
                <?php if (in_array($kyc['status'], ['pending', 'under_review'], true)): ?>
                    <form method="POST" action="kyc-action.php"
                        class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-6 space-y-5"
                        id="kycActionForm">
                        <?php nb_csrf_field(); ?>
                        <input type="hidden" name="kyc_id" value="<?= (int) $kyc['kyc_id'] ?>">

                        <div class="flex items-center gap-3 pb-3 border-b border-gray-100 dark:border-gray-800">
                            <span
                                class="w-10 h-10 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-sm">
                                <i class="fa-solid fa-gavel"></i>
                            </span>
                            <div>
                                <h3 class="text-base font-bold">Your Decision</h3>
                                <p class="text-xs text-gray-500 mt-0.5">Review documents and AI analysis above, then decide.
                                </p>
                            </div>
                        </div>

                        <div>
                            <label
                                class="block text-xs font-bold uppercase tracking-wider text-gray-600 dark:text-gray-300 mb-2">
                                Admin Notes (internal)
                            </label>
                            <textarea name="admin_notes" rows="2" maxlength="500" placeholder="Optional internal notes..."
                                class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"></textarea>
                        </div>

                        <div>
                            <label
                                class="block text-xs font-bold uppercase tracking-wider text-gray-600 dark:text-gray-300 mb-2">
                                Rejection Reason <span class="text-gray-400 font-normal normal-case">(required if
                                    rejecting)</span>
                            </label>
                            <select name="rejection_reason" id="rejectionReason"
                                class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">— Select a reason —</option>
                                <option value="CNIC image is unclear or unreadable">CNIC image is unclear or unreadable
                                </option>
                                <option value="Selfie does not match CNIC photo">Selfie does not match CNIC photo</option>
                                <option value="Information does not match CNIC">Information does not match CNIC</option>
                                <option value="CNIC appears to be expired">CNIC appears to be expired</option>
                                <option value="Document appears to be tampered or fake">Document appears to be tampered or
                                    fake</option>
                                <option value="Date of birth indicates user is under 18">Date of birth indicates user is
                                    under 18</option>
                                <option value="Bank details invalid or mismatched">Bank details invalid or mismatched
                                </option>
                                <option value="Duplicate or suspicious submission">Duplicate or suspicious submission
                                </option>
                                <option value="other">Other (see admin notes)</option>
                            </select>
                        </div>

                        <?php if ($ai_status === 'completed'): ?>
                            <div class="p-3 rounded-xl bg-indigo-500/5 border border-indigo-500/20 flex items-start gap-2">
                                <i class="fa-solid fa-robot text-indigo-500 text-xs mt-0.5"></i>
                                <p class="text-[11px] text-indigo-800 dark:text-indigo-300 leading-relaxed">
                                    AI Recommendation: <strong><?= ucfirst($ai_rec) ?></strong> (<?= $ai_score ?>% confidence).
                                    Your decision will be logged for audit.
                                </p>
                            </div>
                        <?php endif; ?>

                        <div class="grid grid-cols-2 gap-3 pt-2">
                            <button type="submit" name="action" value="reject"
                                class="py-3.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-bold text-sm shadow-lg shadow-rose-600/20 transition hover:-translate-y-0.5"
                                onclick="return confirmReject(event)">
                                <i class="fa-solid fa-circle-xmark mr-1.5"></i> Reject
                            </button>
                            <button type="submit" name="action" value="approve"
                                class="py-3.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-bold text-sm shadow-lg shadow-emerald-600/30 transition hover:-translate-y-0.5"
                                onclick="return confirm('Approve this KYC? User will be granted verified status.');">
                                <i class="fa-solid fa-circle-check mr-1.5"></i> Approve
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div
                        class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-6 text-center">
                        <div
                            class="w-14 h-14 mx-auto rounded-2xl <?= $meta['bg'] ?> <?= $meta['text'] ?> flex items-center justify-center text-2xl mb-4">
                            <i class="fa-solid <?= $meta['icon'] ?>"></i>
                        </div>
                        <div class="text-sm text-gray-500">
                            This KYC has already been <strong class="<?= $meta['text'] ?>"><?= $meta['label'] ?></strong>.
                        </div>
                        <?php if (!empty($kyc['reviewer_name']) && !empty($kyc['reviewed_at'])): ?>
                            <div class="text-xs text-gray-400 mt-2">
                                Reviewed by <?= htmlspecialchars($kyc['reviewer_name']) ?>
                                on <?= date('d M Y, h:i A', strtotime($kyc['reviewed_at'])) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($kyc['rejection_reason'])): ?>
                            <div
                                class="mt-3 p-3 rounded-xl bg-rose-500/10 border border-rose-500/20 text-xs text-rose-700 dark:text-rose-300">
                                <strong>Reason:</strong> <?= htmlspecialchars($kyc['rejection_reason']) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($kyc['admin_notes'])): ?>
                            <div
                                class="mt-3 p-3 rounded-xl bg-gray-100 dark:bg-slate-800 text-xs text-gray-600 dark:text-gray-400 text-left">
                                <strong>Admin notes:</strong> <?= htmlspecialchars($kyc['admin_notes']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Lightbox -->
    <div id="lightbox" class="lightbox" onclick="this.classList.remove('active')">
        <img id="lightboxImg" src="" alt="">
    </div>

    <script>
        function openLightbox(src) {
            document.getElementById('lightboxImg').src = src;
            document.getElementById('lightbox').classList.add('active');
        }
        function confirmReject(e) {
            const reason = document.getElementById('rejectionReason').value;
            if (!reason) {
                e.preventDefault();
                alert('Please select a rejection reason.');
                return false;
            }
            return confirm('Reject this KYC? The user will be notified with the reason.');
        }
        (function () {
            const html = document.documentElement;
            const saved = localStorage.getItem('theme');
            if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) html.classList.add('dark');
            document.getElementById('themeToggle')?.addEventListener('click', () => {
                html.classList.toggle('dark');
                localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light');
            });
            document.addEventListener('keydown', e => {
                if (e.key === 'Escape') document.getElementById('lightbox').classList.remove('active');
            });
        })();
    </script>
    <?php if (file_exists(__DIR__ . '/../includes/chat-widget.php'))
        require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>