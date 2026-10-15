<?php
session_start();

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "seller") {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";
require_once "../includes/activity.php";

date_default_timezone_set('Asia/Karachi');

$seller_id = intval($_SESSION["user_id"]);

/* =========================================================
   ★ KYC GATE — Seller must be approved to create auctions
========================================================= */
$kyc_stmt = $conn->prepare("SELECT seller_kyc_status FROM users WHERE user_id = ? LIMIT 1");
$kyc_stmt->bind_param("i", $seller_id);
$kyc_stmt->execute();
$kyc_row = $kyc_stmt->get_result()->fetch_assoc();
$kyc_stmt->close();
$seller_kyc_status = $kyc_row['seller_kyc_status'] ?? 'unverified';

if ($seller_kyc_status !== 'approved') {
    $_SESSION['error'] = match($seller_kyc_status) {
        'pending'  => 'Your seller KYC is under review. You can create auctions once verified.',
        'rejected' => 'Your seller KYC was rejected. Please resubmit to start selling.',
        default    => 'Please complete seller KYC verification before creating auctions.',
    };
    header("Location: kyc-status.php");
    exit();
}

/* Load existing draft if resuming */
$draft_id = intval($_GET["draft"] ?? 0);
$draft = null;
if ($draft_id > 0) {
    $d_stmt = $conn->prepare("SELECT * FROM auctions WHERE auction_id = ? AND seller_id = ? AND auction_status = 'draft' LIMIT 1");
    $d_stmt->bind_param("ii", $draft_id, $seller_id);
    $d_stmt->execute();
    $draft = $d_stmt->get_result()->fetch_assoc();
    $d_stmt->close();
}

/* Seller info */
$user_stmt = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ?");
$user_stmt->bind_param("i", $seller_id);
$user_stmt->execute();
$user_info = $user_stmt->get_result()->fetch_assoc();
$full_name = $user_info["full_name"] ?? "Seller";
$avatar = $user_info["avatar"] ?? "";
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));
$unread_notifications = nb_unread_count($conn, $seller_id);

/* Categories */
$categories = $conn->query("SELECT category_id, category_name FROM categories WHERE status = 'active' ORDER BY category_name ASC")
    ->fetch_all(MYSQLI_ASSOC);

/* Sidebar counts */
$sidebar_total = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id")->fetch_assoc()["t"] ?? 0;
$sidebar_active = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'active'")->fetch_assoc()["t"] ?? 0;
$sidebar_pending = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'pending'")->fetch_assoc()["t"] ?? 0;
$sidebar_drafts = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'draft'")->fetch_assoc()["t"] ?? 0;
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $draft ? 'Resume Draft' : 'Create Auction' ?> | NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = {darkMode: 'class', theme: {extend: {fontFamily: {sans: ['Inter', 'system-ui', 'sans-serif']}}}};</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; }
        .glass { backdrop-filter: blur(16px) saturate(180%); }
        .upload-zone.dragover { border-color: #6366f1; background-color: rgb(99 102 241 / .05); }
        .field-error { border-color: #f43f5e !important; }
        .field-ok { border-color: #10b981 !important; }
        @keyframes aiPulse { 0%,100% { opacity: 1; } 50% { opacity: .55; } }
        .ai-pulsing { animation: aiPulse 1.2s ease-in-out infinite; }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 antialiased min-h-screen">
    <div class="flex min-h-screen">

        <?php
        $sidebar_active_page = 'create';
        include __DIR__ . '/../includes/seller-sidebar.php';
        ?>

        <main class="flex-1 lg:ml-64 min-w-0">

            <header class="sticky top-0 z-30 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
                <div class="h-20 px-4 sm:px-8 flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3 min-w-0">
                        <a href="my-auctions.php" class="hidden sm:flex w-10 h-10 rounded-xl bg-gray-100 dark:bg-slate-800 items-center justify-center">
                            <i class="fa-solid fa-arrow-left"></i>
                        </a>
                        <div class="min-w-0">
                            <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-0.5 flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-violet-500"></span> Seller Panel
                            </div>
                            <h1 class="text-lg sm:text-xl font-black truncate"><?= $draft ? 'Resume Draft' : 'Create Auction' ?></h1>
                        </div>
                    </div>
                    <button id="themeToggle" class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                        <i class="fa-solid fa-moon dark:hidden"></i><i class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                    </button>
                </div>
            </header>

            <div class="p-4 sm:p-6 lg:p-8 space-y-6">

                <?php if (isset($_SESSION["error"])): ?>
                    <div class="flex items-start gap-3 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30">
                        <div class="w-9 h-9 rounded-lg bg-rose-500/20 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0">
                            <i class="fa-solid fa-circle-exclamation"></i></div>
                        <p class="text-sm text-rose-700 dark:text-rose-300 font-medium self-center">
                            <?= htmlspecialchars($_SESSION["error"]); unset($_SESSION["error"]); ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($draft): ?>
                    <div class="flex items-start gap-3 p-4 rounded-2xl bg-amber-500/10 border border-amber-500/30">
                        <i class="fa-solid fa-pen-to-square text-amber-500 mt-1"></i>
                        <div>
                            <p class="text-sm text-amber-800 dark:text-amber-300 font-semibold">Resuming draft #<?= str_pad($draft['auction_id'], 5, '0', STR_PAD_LEFT) ?></p>
                            <p class="text-xs text-amber-700/80 dark:text-amber-400/80 mt-0.5">Changes will not be visible until you submit for review.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <form action="create-auction-process.php" method="POST" enctype="multipart/form-data" id="auctionForm">
                    <?php nb_csrf_field(); ?>
                    <?php if ($draft): ?>
                        <input type="hidden" name="draft_id" value="<?= (int)$draft['auction_id'] ?>">
                    <?php endif; ?>
                    <input type="hidden" name="action" id="formAction" value="submit">

                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

                        <!-- LEFT -->
                        <div class="lg:col-span-7 space-y-6">

                            <!-- Basic Info -->
                            <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm">
                                <div class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                    <span class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm"><i class="fa-solid fa-circle-info"></i></span>
                                    <div>
                                        <h3 class="text-base font-bold">Item Information</h3>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Basic details of your auction</p>
                                    </div>
                                </div>
                                <div class="p-5 sm:p-6 space-y-5">
                                    <div>
                                        <div class="flex items-center justify-between mb-2">
                                            <label for="title" class="block text-xs font-bold uppercase tracking-wider">Auction Title <span class="text-rose-500">*</span></label>
                                            <span class="text-[10px] text-gray-400 tabular-nums"><span id="titleCount">0</span>/120</span>
                                        </div>
                                        <input type="text" name="title" id="title" required maxlength="120"
                                            value="<?= htmlspecialchars($draft['title'] ?? '') ?>"
                                            placeholder="Example: iPhone 15 Pro Max 256GB"
                                            class="w-full px-4 py-3.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                        <p id="titleHint" class="text-[11px] mt-1.5 text-gray-400">Keep it short, descriptive, and specific.</p>
                                    </div>

                                    <div>
                                        <label for="category_id" class="block text-xs font-bold uppercase tracking-wider mb-2">Category <span class="text-rose-500">*</span></label>
                                        <select name="category_id" id="category_id" required
                                            class="w-full px-4 py-3.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                            <option value="">Select a category...</option>
                                            <?php foreach ($categories as $c): ?>
                                                <option value="<?= (int) $c['category_id'] ?>"
                                                    <?= ($draft['category_id'] ?? 0) == $c['category_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($c['category_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- ★ AI DESCRIPTION BLOCK START -->
                                    <div>
                                        <div class="flex items-center justify-between mb-2 gap-2 flex-wrap">
                                            <label for="description" class="block text-xs font-bold uppercase tracking-wider">
                                                Description <span class="text-rose-500">*</span>
                                            </label>
                                            <div class="flex items-center gap-2">
                                                <span class="text-[10px] text-gray-400 tabular-nums">
                                                    <span id="descCount">0</span>/2000
                                                </span>
                                                <button type="button" id="generateDescriptionBtn"
                                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gradient-to-r from-violet-500 to-indigo-500 hover:from-violet-600 hover:to-indigo-600 disabled:from-gray-400 disabled:to-gray-500 disabled:cursor-not-allowed text-white text-[11px] font-bold shadow-sm transition-all">
                                                    <i class="fa-solid fa-wand-magic-sparkles text-[10px]" id="aiDescIcon"></i>
                                                    <span id="aiDescBtnText">Generate with AI</span>
                                                </button>
                                            </div>
                                        </div>
                                        <textarea name="description" id="description" rows="6" required maxlength="2000"
                                            placeholder="Describe your item — condition, features, dimensions, included accessories... ya upar AI button dabao."
                                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"><?= htmlspecialchars($draft['description'] ?? '') ?></textarea>
                                        <p id="aiDescStatus" class="text-[11px] mt-1.5 hidden"></p>
                                    </div>
                                    <!-- ★ AI DESCRIPTION BLOCK END -->
                                </div>
                            </div>

                            <!-- Pricing -->
                            <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm">
                                <div class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                    <span class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm"><i class="fa-solid fa-coins"></i></span>
                                    <div><h3 class="text-base font-bold">Pricing</h3><p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Set starting price and bid rules</p></div>
                                </div>
                                <div class="p-5 sm:p-6 grid grid-cols-1 sm:grid-cols-2 gap-5">
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Starting Price <span class="text-rose-500">*</span></label>
                                        <div class="relative">
                                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-xs font-bold">PKR</span>
                                            <input type="number" name="starting_price" id="starting_price" min="1" step="1" required
                                                value="<?= htmlspecialchars($draft['starting_price'] ?? '') ?>"
                                                class="w-full pl-14 pr-4 py-3.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 tabular-nums">
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Bid Increment <span class="text-rose-500">*</span></label>
                                        <div class="relative">
                                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-xs font-bold">PKR</span>
                                            <input type="number" name="bid_increment" id="bid_increment" min="1" step="1" required
                                                value="<?= htmlspecialchars($draft['bid_increment'] ?? 500) ?>"
                                                class="w-full pl-14 pr-4 py-3.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 tabular-nums">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Schedule -->
                            <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm">
                                <div class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                    <span class="w-10 h-10 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-sm"><i class="fa-solid fa-calendar-days"></i></span>
                                    <div><h3 class="text-base font-bold">Schedule</h3><p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Set start and end times</p></div>
                                </div>
                                <div class="p-5 sm:p-6 space-y-5">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                        <div>
                                            <label class="block text-xs font-bold uppercase tracking-wider mb-2">Start <span class="text-rose-500">*</span></label>
                                            <input type="datetime-local" id="start_time" name="start_time" required
                                                value="<?= !empty($draft['start_time']) ? date('Y-m-d\TH:i', strtotime($draft['start_time'])) : '' ?>"
                                                class="w-full px-4 py-3.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-bold uppercase tracking-wider mb-2">End <span class="text-rose-500">*</span></label>
                                            <input type="datetime-local" id="end_time" name="end_time" required
                                                value="<?= !empty($draft['end_time']) ? date('Y-m-d\TH:i', strtotime($draft['end_time'])) : '' ?>"
                                                class="w-full px-4 py-3.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                        </div>
                                    </div>
                                    <div id="dateError" class="hidden text-[11px] text-rose-500 font-semibold flex items-center gap-1.5">
                                        <i class="fa-solid fa-circle-exclamation"></i> <span></span>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wider mb-3">
                                            <i class="fa-solid fa-bolt text-amber-500 text-[10px] mr-1"></i>Quick Duration
                                        </label>
                                        <div class="flex flex-wrap gap-2">
                                            <?php foreach ([[3, '+3 Mins'], [60, '+1 Hour'], [1440, '+1 Day'], [10080, '+7 Days'], [43200, '+30 Days']] as $b): ?>
                                                <button type="button" onclick="setTestDuration(<?= $b[0] ?>)"
                                                    class="px-4 py-2.5 rounded-xl bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-500/20 hover:bg-amber-500 hover:text-white text-xs font-bold transition-all">
                                                    <i class="fa-solid fa-stopwatch text-[10px] mr-1"></i> <?= $b[1] ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- RIGHT -->
                        <div class="lg:col-span-5 space-y-6">

                            <!-- Image -->
                            <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm">
                                <div class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                    <span class="w-10 h-10 rounded-xl bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center text-sm"><i class="fa-solid fa-image"></i></span>
                                    <div>
                                        <h3 class="text-base font-bold">Item Image</h3>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Drag & drop or click · Max 5MB</p>
                                    </div>
                                </div>
                                <div class="p-5 sm:p-6">
                                    <label for="image-input"
                                        class="upload-zone block cursor-pointer rounded-2xl border-2 border-dashed border-gray-200 dark:border-gray-700 hover:border-indigo-500 bg-gray-50 dark:bg-slate-800/50 transition-all p-6 text-center">
                                        <div id="uploadPlaceholder">
                                            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 text-indigo-500 flex items-center justify-center text-2xl mx-auto mb-4">
                                                <i class="fa-solid fa-cloud-arrow-up"></i></div>
                                            <p class="text-sm font-bold">Drop image here or click</p>
                                            <p class="text-xs text-gray-500 mt-1.5">PNG, JPG, or WEBP · Max 5MB</p>
                                        </div>
                                        <div id="uploadPreview" class="hidden">
                                            <div class="relative rounded-xl overflow-hidden aspect-video bg-gray-100 dark:bg-slate-800">
                                                <img id="previewImage"
                                                    src="<?= !empty($draft['image']) ? '../uploads/auctions/' . htmlspecialchars($draft['image']) : '' ?>"
                                                    class="w-full h-full object-cover" alt="">
                                                <button type="button" id="removeImage"
                                                    class="absolute top-2 right-2 w-8 h-8 rounded-lg bg-rose-500 text-white flex items-center justify-center shadow-lg hover:bg-rose-600">
                                                    <i class="fa-solid fa-xmark text-xs"></i>
                                                </button>
                                                <span class="absolute bottom-2 left-2 px-2 py-0.5 rounded-md bg-black/60 text-white text-[9px] font-bold uppercase tracking-wider">Primary</span>
                                            </div>
                                            <p id="previewFilename" class="text-xs font-bold mt-3 truncate">
                                                <?= $draft && !empty($draft['image']) ? htmlspecialchars($draft['image']) : '' ?>
                                            </p>
                                        </div>
                                        <input type="file" name="image" id="image-input" accept="image/*"
                                            <?= $draft && !empty($draft['image']) ? '' : 'required' ?> class="sr-only">
                                    </label>
                                </div>
                            </div>

                            <!-- Live Preview + Actions -->
                            <div class="hidden lg:block lg:sticky lg:top-24 space-y-6">
                                <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm">
                                    <div class="p-5 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-3">
                                            <span class="w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs"><i class="fa-solid fa-eye"></i></span>
                                            <div>
                                                <h3 class="text-sm font-bold">Live Preview</h3>
                                                <p class="text-[10px] text-gray-500 mt-0.5">How buyers will see it</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="p-6">
                                        <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-slate-900 overflow-hidden">
                                            <div class="relative aspect-[4/3] bg-gray-100 dark:bg-slate-800 flex items-center justify-center overflow-hidden">
                                                <div id="previewImagePlaceholder" class="w-full h-full flex flex-col items-center justify-center text-gray-400 <?= $draft && !empty($draft['image']) ? 'hidden' : '' ?>">
                                                    <i class="fa-solid fa-image text-3xl mb-2"></i>
                                                    <span class="text-xs font-medium">No image yet</span>
                                                </div>
                                                <img id="previewImageInline"
                                                    src="<?= !empty($draft['image']) ? '../uploads/auctions/' . htmlspecialchars($draft['image']) : '' ?>"
                                                    class="<?= $draft && !empty($draft['image']) ? '' : 'hidden' ?> absolute inset-0 w-full h-full object-cover" alt="">
                                            </div>
                                            <div class="p-5">
                                                <h4 id="previewTitle" class="font-bold line-clamp-2 leading-snug min-h-10">
                                                    <?= htmlspecialchars($draft['title'] ?? 'Your auction title here') ?>
                                                </h4>
                                                <div class="mt-4 flex items-baseline justify-between">
                                                    <div>
                                                        <span class="text-[10px] text-gray-400 block mb-0.5">Starting Price</span>
                                                        <strong id="previewPrice" class="text-xl font-black tabular-nums">Rs. 0</strong>
                                                    </div>
                                                    <div class="text-right">
                                                        <span class="text-[10px] text-gray-400 block mb-0.5">Increment</span>
                                                        <strong id="previewIncrement" class="text-xs font-bold text-indigo-600 dark:text-indigo-400 tabular-nums">Rs. 500</strong>
                                                    </div>
                                                </div>
                                                <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800 flex items-center gap-2 text-[11px] text-gray-500">
                                                    <i class="fa-solid fa-hourglass-start text-[9px]"></i>
                                                    <span id="previewDuration">Set a duration to see it here</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Action panel -->
                                <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 sm:p-6 space-y-3">
                                    <button type="submit" onclick="document.getElementById('formAction').value='submit'"
                                        class="group w-full py-4 rounded-xl bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 text-white font-bold text-sm shadow-lg flex items-center justify-center gap-2">
                                        <i class="fa-solid fa-paper-plane"></i> Submit for Review
                                    </button>
                                    <button type="submit" onclick="document.getElementById('formAction').value='draft'"
                                        class="w-full py-3 rounded-xl bg-amber-500/10 hover:bg-amber-500/20 text-amber-700 dark:text-amber-400 border border-amber-500/20 font-bold text-sm flex items-center justify-center gap-2 transition-all">
                                        <i class="fa-solid fa-floppy-disk"></i> Save as Draft
                                    </button>
                                    <a href="my-auctions.php"
                                        class="block w-full py-3 rounded-xl text-center text-sm font-semibold text-gray-500 hover:text-gray-900 dark:hover:text-white">Cancel</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
    (function () {
        'use strict';

        /* ============ THEME TOGGLE ============ */
        const html = document.documentElement, t = document.getElementById('themeToggle'), s = localStorage.getItem('theme');
        if (s === 'dark' || (!s && window.matchMedia('(prefers-color-scheme: dark)').matches)) html.classList.add('dark');
        t?.addEventListener('click', () => {html.classList.toggle('dark'); localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light')});

        /* ============ ELEMENTS ============ */
        const titleInput   = document.getElementById('title');
        const descInput    = document.getElementById('description');
        const titleCount   = document.getElementById('titleCount');
        const descCount    = document.getElementById('descCount');
        const priceInput   = document.getElementById('starting_price');
        const incInput     = document.getElementById('bid_increment');
        const catInput     = document.getElementById('category_id');
        const startInput   = document.getElementById('start_time');
        const endInput     = document.getElementById('end_time');
        const previewTitle = document.getElementById('previewTitle');
        const previewPrice = document.getElementById('previewPrice');
        const previewInc   = document.getElementById('previewIncrement');
        const previewDur   = document.getElementById('previewDuration');
        const previewImg   = document.getElementById('previewImageInline');
        const previewPH    = document.getElementById('previewImagePlaceholder');
        const imgInput     = document.getElementById('image-input');
        const upPH         = document.getElementById('uploadPlaceholder');
        const upPV         = document.getElementById('uploadPreview');
        const pi           = document.getElementById('previewImage');
        const pf           = document.getElementById('previewFilename');
        const removeBtn    = document.getElementById('removeImage');
        const upZone       = document.querySelector('.upload-zone');
        const dateErr      = document.getElementById('dateError');

        /* ============ AUTO-SAVE DRAFT TO LOCALSTORAGE ============ */
        const FIELDS = ['title','description','starting_price','bid_increment','category_id','start_time','end_time'];
        const DRAFT_KEY = 'nb_auction_draft_<?= $seller_id ?>';

        function saveLocal() {
            const data = {};
            FIELDS.forEach(f => { const el = document.getElementById(f); if (el) data[f] = el.value; });
            localStorage.setItem(DRAFT_KEY, JSON.stringify(data));
        }
        function loadLocal() {
            const raw = localStorage.getItem(DRAFT_KEY);
            if (!raw) return;
            try {
                const data = JSON.parse(raw);
                FIELDS.forEach(f => {
                    const el = document.getElementById(f);
                    if (el && !el.value && data[f]) el.value = data[f];
                });
            } catch(e){}
        }
        <?php if (!$draft): ?>loadLocal();<?php endif; ?>
        FIELDS.forEach(f => { const el = document.getElementById(f); el?.addEventListener('input', saveLocal); });
        document.getElementById('auctionForm')?.addEventListener('submit', () => localStorage.removeItem(DRAFT_KEY));

        /* ============ QUICK DURATION ============ */
        window.setTestDuration = function (minutes) {
            const now = new Date();
            const st = new Date(now.getTime() + 10000);
            const et = new Date(st.getTime() + minutes * 60000);
            const fmt = d => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}T${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
            startInput.value = fmt(st);
            endInput.value = fmt(et);
            updatePreview(); saveLocal();
        };

        /* ============ LIVE PREVIEW ============ */
        function fmtRs(n){return 'Rs. ' + Number(n||0).toLocaleString('en-PK', {maximumFractionDigits: 0});}
        function updatePreview(){
            previewTitle.textContent = (titleInput?.value||'').trim() || 'Your auction title here';
            previewPrice.textContent = fmtRs(priceInput?.value);
            previewInc.textContent = fmtRs(incInput?.value || 500);
            if (titleCount) titleCount.textContent = titleInput?.value.length || 0;
            if (descCount) descCount.textContent = descInput?.value.length || 0;
            const sV=startInput?.value, eV=endInput?.value;
            if (sV && eV) {
                const d = new Date(eV) - new Date(sV);
                if (d > 0) {
                    const days=Math.floor(d/86400000), hrs=Math.floor((d%86400000)/3600000), mins=Math.floor((d%3600000)/60000);
                    const parts=[]; if(days>0)parts.push(days+'d'); if(hrs>0)parts.push(hrs+'h'); if(mins>0&&days===0)parts.push(mins+'m');
                    previewDur.textContent = 'Runs for ' + (parts.join(' ')||'< 1 min');
                    dateErr.classList.add('hidden');
                } else {
                    previewDur.textContent = '⚠ End must be after start';
                    dateErr.querySelector('span').textContent = 'Auction must end after start date.';
                    dateErr.classList.remove('hidden');
                }
            } else { previewDur.textContent = 'Set a duration to see it here'; dateErr.classList.add('hidden'); }
        }
        [titleInput,descInput,priceInput,incInput,startInput,endInput].forEach(el => el?.addEventListener('input', updatePreview));
        [startInput,endInput].forEach(el => el?.addEventListener('change', updatePreview));
        updatePreview();

        /* ============ IMAGE UPLOAD ============ */
        imgInput?.addEventListener('change', function(){
            const f=this.files?.[0]; if(!f||!f.type.startsWith('image/'))return;
            if (f.size > 5*1024*1024) { alert('Image must be under 5MB'); this.value=''; return; }
            const r=new FileReader();
            r.onload=e=>{ pi.src=e.target.result; pf.textContent=f.name;
                previewImg.src=e.target.result; previewImg.classList.remove('hidden'); previewPH.classList.add('hidden');
                upPH.classList.add('hidden'); upPV.classList.remove('hidden'); };
            r.readAsDataURL(f);
        });
        removeBtn?.addEventListener('click', e=>{
            e.preventDefault(); e.stopPropagation();
            if(imgInput) imgInput.value='';
            previewImg.src=''; previewImg.classList.add('hidden'); previewPH.classList.remove('hidden');
            upPV.classList.add('hidden'); upPH.classList.remove('hidden');
            const hidden = document.createElement('input'); hidden.type='hidden'; hidden.name='remove_image'; hidden.value='1';
            document.getElementById('auctionForm').appendChild(hidden);
        });
        ['dragenter','dragover'].forEach(ev => upZone?.addEventListener(ev, e=>{e.preventDefault(); upZone.classList.add('dragover')}));
        ['dragleave','drop'].forEach(ev => upZone?.addEventListener(ev, e=>{e.preventDefault(); upZone.classList.remove('dragover')}));
        upZone?.addEventListener('drop', e=>{
            const files=e.dataTransfer?.files;
            if(files?.[0] && imgInput){ imgInput.files=files; imgInput.dispatchEvent(new Event('change')); }
        });

        /* ============================================================
           ★ AI DESCRIPTION GENERATOR
           ============================================================ */
        const aiBtn     = document.getElementById('generateDescriptionBtn');
        const aiBtnText = document.getElementById('aiDescBtnText');
        const aiBtnIcon = document.getElementById('aiDescIcon');
        const aiStatus  = document.getElementById('aiDescStatus');
        const csrfMeta  = document.querySelector('meta[name="csrf-token"]');
        const CSRF      = csrfMeta ? csrfMeta.content : '';

        function setAiStatus(msg, type) {
            aiStatus.textContent = msg || '';
            aiStatus.className = 'text-[11px] mt-1.5 ' + (
                type === 'ok'   ? 'text-emerald-600 dark:text-emerald-400' :
                type === 'err'  ? 'text-rose-600 dark:text-rose-400' :
                type === 'info' ? 'text-indigo-600 dark:text-indigo-400' :
                                  'text-gray-500 dark:text-gray-400'
            );
            if (!msg) aiStatus.classList.add('hidden');
            else aiStatus.classList.remove('hidden');
        }

        // Adjust button label if description already has text
        if (descInput && descInput.value.trim()) {
            aiBtnText.textContent = 'Regenerate with AI';
        }

        aiBtn?.addEventListener('click', async function () {
            const title = (titleInput.value || '').trim();
            if (!title) {
                setAiStatus('Pehle auction title likhein.', 'err');
                titleInput.focus();
                titleInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            // Category name for context
            let categoryName = '';
            if (catInput && catInput.selectedIndex >= 0) {
                const opt = catInput.options[catInput.selectedIndex];
                if (opt && opt.value) categoryName = opt.textContent.trim();
            }

            // Existing description as seed notes
            const existing = (descInput.value || '').trim();

            // Lock button
            aiBtn.disabled = true;
            const prevIconClass = aiBtnIcon.className;
            aiBtnIcon.className = 'fa-solid fa-spinner fa-spin text-[10px]';
            aiBtnText.textContent = existing ? 'Regenerating…' : 'Generating…';
            aiBtn.classList.add('ai-pulsing');
            setAiStatus('✨ AI description likh raha hai… (10–20 seconds)', 'info');

            try {
                const res = await fetch('ai-generate-description.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        title: title,
                        category: categoryName,
                        features: existing,
                        csrf_token: CSRF
                    })
                });

                let data;
                try { data = await res.json(); }
                catch (parseErr) { data = { success: false, error: 'Invalid server response.' }; }

                if (!res.ok || !data.success) {
                    throw new Error(data.error || 'AI error. Please try again.');
                }

                descInput.value = data.description || '';
                // Trigger input listeners → counter update + localStorage autosave
                descInput.dispatchEvent(new Event('input', { bubbles: true }));

                descInput.focus();
                descInput.scrollIntoView({ behavior: 'smooth', block: 'center' });

                const rem = typeof data.remaining === 'number' ? ` · ${data.remaining} left this hour` : '';
                setAiStatus('✅ Description ready! Aap edit kar sakte hain.' + rem, 'ok');

            } catch (err) {
                setAiStatus('❌ ' + (err.message || 'Something went wrong.'), 'err');
            } finally {
                aiBtn.disabled = false;
                aiBtnIcon.className = prevIconClass || 'fa-solid fa-wand-magic-sparkles text-[10px]';
                aiBtn.classList.remove('ai-pulsing');
                aiBtnText.textContent = descInput.value.trim() ? 'Regenerate with AI' : 'Generate with AI';
            }
        });

    })();
    </script>
<?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>
</html>