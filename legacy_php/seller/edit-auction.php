<?php
session_start();

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "seller") {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";

date_default_timezone_set('Asia/Karachi');

$seller_id = intval($_SESSION["user_id"]);
$auction_id = intval($_GET["id"] ?? 0);

/* =========================================================
   ★ KYC GATE
========================================================= */
$kyc_chk = $conn->prepare("SELECT seller_kyc_status FROM users WHERE user_id = ? LIMIT 1");
$kyc_chk->bind_param("i", $seller_id);
$kyc_chk->execute();
$kyc_res = $kyc_chk->get_result()->fetch_assoc();
$kyc_chk->close();

if (($kyc_res['seller_kyc_status'] ?? 'unverified') !== 'approved') {
    $_SESSION["error"] = "Please complete seller KYC verification first.";
    header("Location: kyc-status.php");
    exit();
}

/* Seller info */
$user_stmt = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ?");
$user_stmt->bind_param("i", $seller_id);
$user_stmt->execute();
$user_info = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

$full_name = $user_info["full_name"] ?? "Seller";
$avatar = $user_info["avatar"] ?? "";
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));
$unread_notifications = nb_unread_count($conn, $seller_id);

/* Fetch auction + ownership + bid count */
$stmt = $conn->prepare("
    SELECT a.*,
        (SELECT COUNT(*) FROM bids WHERE bids.auction_id = a.auction_id) AS bid_count
    FROM auctions a
    WHERE a.auction_id = ? AND a.seller_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $auction_id, $seller_id);
$stmt->execute();
$auction = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$auction) {
    $_SESSION["error"] = "Auction not found.";
    header("Location: my-auctions.php");
    exit();
}

$status = strtolower($auction["auction_status"]);
$is_draft = ($status === 'draft');

if (intval($auction["bid_count"]) > 0) {
    $_SESSION["error"] = "This auction already has bids and can no longer be edited.";
    header("Location: my-auctions.php");
    exit();
}
if (in_array($status, ['completed', 'ended', 'cancelled', 'rejected', 'sold', 'unsold', 'relisted'], true)) {
    $_SESSION["error"] = "This auction can no longer be edited.";
    header("Location: my-auctions.php");
    exit();
}

$categories = $conn->query("SELECT category_id, category_name FROM categories WHERE status = 'active' ORDER BY category_name ASC")
    ->fetch_all(MYSQLI_ASSOC);

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
    <title><?= $is_draft ? 'Edit Draft' : 'Edit Auction' ?> · NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = {darkMode: 'class', theme: {extend: {fontFamily: {sans: ['Inter', 'system-ui', 'sans-serif']}}}};</script>
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

        .custom-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L2 4h8z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            appearance: none;
        }

        .upload-zone.dragover {
            border-color: #6366f1;
            background-color: rgb(99 102 241 / .05);
        }

        @keyframes aiPulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: .55;
            }
        }

        .ai-pulsing {
            animation: aiPulse 1.2s ease-in-out infinite;
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 antialiased min-h-screen">

    <div class="flex min-h-screen">

        <?php
        $sidebar_active_page = 'my-auctions';
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
                        <a href="my-auctions.php"
                            class="hidden sm:flex w-10 h-10 rounded-xl bg-gray-100 dark:bg-slate-800 items-center justify-center hover:bg-gray-200 dark:hover:bg-slate-700">
                            <i class="fa-solid fa-arrow-left"></i>
                        </a>
                        <div class="min-w-0">
                            <div
                                class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-0.5 flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-violet-500"></span> Seller Panel
                            </div>
                            <h1 class="text-lg sm:text-xl font-black truncate">
                                <?= $is_draft ? 'Edit Draft' : 'Edit Auction' ?></h1>
                        </div>
                    </div>
                    <button id="themeToggle"
                        class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition-all">
                        <i class="fa-solid fa-moon dark:hidden"></i><i
                            class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                    </button>
                </div>
            </header>

            <div class="p-4 sm:p-6 lg:p-8 space-y-6">

                <?php if ($is_draft): ?>
                    <div class="flex items-start gap-3 p-4 rounded-2xl bg-amber-500/10 border border-amber-500/30">
                        <i class="fa-solid fa-pen text-amber-500 mt-1"></i>
                        <div>
                            <p class="text-sm text-amber-800 dark:text-amber-300 font-semibold">Draft Mode</p>
                            <p class="text-xs text-amber-700/80 dark:text-amber-400/80 mt-0.5">This auction is still a
                                draft. Save as draft to keep editing later, or submit for admin review.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($_SESSION["error"])): ?>
                    <div class="flex items-start gap-3 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30">
                        <i class="fa-solid fa-circle-exclamation text-rose-600 dark:text-rose-400 mt-1"></i>
                        <p class="text-sm text-rose-700 dark:text-rose-300 font-medium self-center">
                            <?= htmlspecialchars($_SESSION["error"]);
                            unset($_SESSION["error"]); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <form action="edit-auction-process.php" method="POST" enctype="multipart/form-data"
                    class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start" id="editForm">
                    <?php nb_csrf_field(); ?>
                    <input type="hidden" name="auction_id" value="<?= (int) $auction['auction_id'] ?>">
                    <input type="hidden" name="action" id="formAction" value="submit">

                    <!-- LEFT -->
                    <div class="lg:col-span-7 space-y-6">

                        <!-- Basic Info -->
                        <div
                            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                            <div
                                class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                <span
                                    class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm">
                                    <i class="fa-solid fa-circle-info"></i>
                                </span>
                                <div>
                                    <h3 class="text-base font-bold">Item Information</h3>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Basic details of your
                                        auction</p>
                                </div>
                            </div>
                            <div class="p-5 sm:p-6 space-y-5">
                                <div>
                                    <div class="flex items-center justify-between mb-2">
                                        <label for="title"
                                            class="block text-xs font-bold uppercase tracking-wider">Auction Title <span
                                                class="text-rose-500">*</span></label>
                                        <span class="text-[10px] text-gray-400 tabular-nums"><span
                                                id="titleCount"><?= mb_strlen($auction['title']) ?></span>/120</span>
                                    </div>
                                    <div class="relative">
                                        <i
                                            class="fa-solid fa-heading absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                        <input type="text" name="title" id="title"
                                            value="<?= htmlspecialchars($auction['title']) ?>" required maxlength="120"
                                            class="w-full pl-11 pr-4 py-3.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    </div>
                                </div>

                                <div>
                                    <label for="category_id"
                                        class="block text-xs font-bold uppercase tracking-wider mb-2">Category <span
                                            class="text-rose-500">*</span></label>
                                    <div class="relative">
                                        <i
                                            class="fa-solid fa-layer-group absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                        <select name="category_id" id="category_id" required
                                            class="custom-select w-full pl-11 pr-10 py-3.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                            <option value="">Select a category...</option>
                                            <?php foreach ($categories as $c): ?>
                                                <option value="<?= (int) $c['category_id'] ?>"
                                                    <?= $c['category_id'] == $auction['category_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($c['category_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- ★ AI DESCRIPTION BLOCK START -->
                                <div>
                                    <div class="flex items-center justify-between mb-2 gap-2 flex-wrap">
                                        <label for="description"
                                            class="block text-xs font-bold uppercase tracking-wider">
                                            Description <span class="text-rose-500">*</span>
                                        </label>
                                        <div class="flex items-center gap-2">
                                            <span class="text-[10px] text-gray-400 tabular-nums">
                                                <span
                                                    id="descCount"><?= mb_strlen($auction['description']) ?></span>/2000
                                            </span>
                                            <button type="button" id="generateDescriptionBtn"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gradient-to-r from-violet-500 to-indigo-500 hover:from-violet-600 hover:to-indigo-600 disabled:from-gray-400 disabled:to-gray-500 disabled:cursor-not-allowed text-white text-[11px] font-bold shadow-sm transition-all">
                                                <i class="fa-solid fa-wand-magic-sparkles text-[10px]"
                                                    id="aiDescIcon"></i>
                                                <span id="aiDescBtnText">Regenerate with AI</span>
                                            </button>
                                        </div>
                                    </div>
                                    <textarea name="description" id="description" rows="6" required maxlength="2000"
                                        placeholder="Describe your item — condition, features, dimensions, included accessories... ya upar AI button dabao."
                                        class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"><?= htmlspecialchars($auction['description']) ?></textarea>
                                    <p id="aiDescStatus" class="text-[11px] mt-1.5 hidden"></p>
                                </div>
                                <!-- ★ AI DESCRIPTION BLOCK END -->
                            </div>
                        </div>

                        <!-- Pricing -->
                        <div
                            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                            <div
                                class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                <span
                                    class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm">
                                    <i class="fa-solid fa-coins"></i>
                                </span>
                                <div>
                                    <h3 class="text-base font-bold">Pricing</h3>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Starting price and bid
                                        rules</p>
                                </div>
                            </div>
                            <div class="p-5 sm:p-6 grid grid-cols-1 sm:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">Starting Price
                                        <span class="text-rose-500">*</span></label>
                                    <div class="relative">
                                        <span
                                            class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-xs font-bold">PKR</span>
                                        <input type="number" name="starting_price" id="starting_price" min="1" step="1"
                                            required value="<?= (int) $auction['starting_price'] ?>"
                                            class="w-full pl-14 pr-4 py-3.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 tabular-nums">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">Bid Increment
                                        <span class="text-rose-500">*</span></label>
                                    <div class="relative">
                                        <span
                                            class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-xs font-bold">PKR</span>
                                        <input type="number" name="bid_increment" id="bid_increment" min="1" step="1"
                                            required value="<?= (int) $auction['bid_increment'] ?>"
                                            class="w-full pl-14 pr-4 py-3.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 tabular-nums">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Schedule -->
                        <div
                            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                            <div
                                class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                <span
                                    class="w-10 h-10 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-sm">
                                    <i class="fa-solid fa-calendar-days"></i>
                                </span>
                                <div>
                                    <h3 class="text-base font-bold">Schedule</h3>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Start and end times</p>
                                </div>
                            </div>
                            <div class="p-5 sm:p-6 space-y-5">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Start <span
                                                class="text-rose-500">*</span></label>
                                        <input type="datetime-local" name="start_time" id="start_time" required
                                            value="<?= date('Y-m-d\TH:i', strtotime($auction['start_time'])) ?>"
                                            class="w-full px-4 py-3.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">End <span
                                                class="text-rose-500">*</span></label>
                                        <input type="datetime-local" name="end_time" id="end_time" required
                                            value="<?= date('Y-m-d\TH:i', strtotime($auction['end_time'])) ?>"
                                            class="w-full px-4 py-3.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    </div>
                                </div>
                                <div id="dateError"
                                    class="hidden text-[11px] text-rose-500 font-semibold flex items-center gap-1.5">
                                    <i class="fa-solid fa-circle-exclamation"></i> <span></span>
                                </div>
                            </div>
                        </div>

                        <!-- Actions (mobile) -->
                        <div
                            class="lg:hidden bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 flex flex-col gap-3">
                            <button type="submit" onclick="document.getElementById('formAction').value='submit'"
                                class="w-full py-4 rounded-xl bg-gradient-to-r from-violet-600 to-indigo-600 text-white font-bold text-sm shadow-lg flex items-center justify-center gap-2">
                                <i class="fa-solid fa-check"></i>
                                <?= $is_draft ? 'Submit for Review' : 'Save Changes' ?>
                            </button>
                            <?php if ($is_draft): ?>
                                <button type="submit" onclick="document.getElementById('formAction').value='draft'"
                                    class="w-full py-3 rounded-xl bg-amber-500/10 hover:bg-amber-500/20 text-amber-700 dark:text-amber-400 border border-amber-500/20 font-bold text-sm flex items-center justify-center gap-2">
                                    <i class="fa-solid fa-floppy-disk"></i> Save as Draft
                                </button>
                            <?php endif; ?>
                            <a href="my-auctions.php"
                                class="w-full py-3 rounded-xl text-center text-sm font-semibold text-gray-500 hover:text-gray-900 dark:hover:text-white">Cancel</a>
                        </div>
                    </div>

                    <!-- RIGHT: Image -->
                    <div class="lg:col-span-5 space-y-6">
                        <div
                            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                            <div
                                class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                <span
                                    class="w-10 h-10 rounded-xl bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center text-sm">
                                    <i class="fa-solid fa-image"></i>
                                </span>
                                <div>
                                    <h3 class="text-base font-bold">Item Image</h3>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Leave empty to keep
                                        current</p>
                                </div>
                            </div>
                            <div class="p-5 sm:p-6">
                                <div
                                    class="mb-4 rounded-2xl overflow-hidden border border-gray-200 dark:border-gray-700 aspect-video bg-gray-100 dark:bg-slate-800">
                                    <?php if (!empty($auction['image'])): ?>
                                        <img id="currentImage"
                                            src="../uploads/auctions/<?= htmlspecialchars($auction['image']) ?>"
                                            class="w-full h-full object-cover" alt="">
                                    <?php else: ?>
                                        <div class="w-full h-full flex items-center justify-center text-gray-400">
                                            <i class="fa-solid fa-image text-3xl"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <label for="image-input"
                                    class="upload-zone block cursor-pointer rounded-2xl border-2 border-dashed border-gray-200 dark:border-gray-700 hover:border-indigo-500 bg-gray-50 dark:bg-slate-800/50 transition-all p-6 text-center">
                                    <div id="uploadPlaceholder">
                                        <div
                                            class="w-14 h-14 rounded-2xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 text-indigo-500 flex items-center justify-center text-xl mx-auto mb-3">
                                            <i class="fa-solid fa-cloud-arrow-up"></i>
                                        </div>
                                        <p class="text-sm font-bold">Click to replace image</p>
                                        <p class="text-xs text-gray-500 mt-1">PNG, JPG, or WEBP · Max 5MB</p>
                                    </div>
                                    <div id="uploadPreview" class="hidden">
                                        <img id="previewImage" src=""
                                            class="w-full aspect-video object-cover rounded-xl" alt="">
                                        <p id="previewFilename" class="text-xs font-bold mt-3 truncate"></p>
                                    </div>
                                    <input type="file" name="image" id="image-input" accept="image/*" class="sr-only">
                                </label>
                            </div>
                        </div>

                        <!-- Save (desktop) -->
                        <div
                            class="hidden lg:block bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 sm:p-6 space-y-3">
                            <button type="submit" onclick="document.getElementById('formAction').value='submit'"
                                class="group w-full py-4 rounded-xl bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 text-white font-bold text-sm shadow-lg flex items-center justify-center gap-2">
                                <i class="fa-solid fa-check"></i>
                                <?= $is_draft ? 'Submit for Review' : 'Save Changes' ?>
                            </button>
                            <?php if ($is_draft): ?>
                                <button type="submit" onclick="document.getElementById('formAction').value='draft'"
                                    class="w-full py-3 rounded-xl bg-amber-500/10 hover:bg-amber-500/20 text-amber-700 dark:text-amber-400 border border-amber-500/20 font-bold text-sm flex items-center justify-center gap-2 transition-all">
                                    <i class="fa-solid fa-floppy-disk"></i> Save as Draft
                                </button>
                            <?php endif; ?>
                            <a href="my-auctions.php"
                                class="block w-full py-3 rounded-xl text-center text-sm font-semibold text-gray-500 hover:text-gray-900 dark:hover:text-white">Cancel</a>
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
            const html = document.documentElement;
            const t = document.getElementById('themeToggle');
            const s = localStorage.getItem('theme');
            if (s === 'dark' || (!s && window.matchMedia('(prefers-color-scheme: dark)').matches)) html.classList.add('dark');
            t?.addEventListener('click', () => {html.classList.toggle('dark'); localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light')});

            /* ============ ELEMENTS ============ */
            const titleInput = document.getElementById('title');
            const descInput = document.getElementById('description');
            const titleCount = document.getElementById('titleCount');
            const descCount = document.getElementById('descCount');
            const catInput = document.getElementById('category_id');
            const startInput = document.getElementById('start_time');
            const endInput = document.getElementById('end_time');
            const dateErr = document.getElementById('dateError');
            const inp = document.getElementById('image-input');
            const ph = document.getElementById('uploadPlaceholder');
            const pv = document.getElementById('uploadPreview');
            const pi = document.getElementById('previewImage');
            const pf = document.getElementById('previewFilename');
            const upZone = document.querySelector('.upload-zone');

            /* ============ CHARACTER COUNTERS ============ */
            titleInput?.addEventListener('input', () => titleCount.textContent = titleInput.value.length);
            descInput?.addEventListener('input', () => descCount.textContent = descInput.value.length);

            /* ============ DATE VALIDATION ============ */
            function checkDates() {
                const sV = startInput.value, eV = endInput.value;
                if (sV && eV) {
                    if (new Date(eV) <= new Date(sV)) {
                        dateErr.querySelector('span').textContent = 'End must be after start.';
                        dateErr.classList.remove('hidden');
                    } else {
                        dateErr.classList.add('hidden');
                    }
                }
            }
            startInput?.addEventListener('change', checkDates);
            endInput?.addEventListener('change', checkDates);

            /* ============ IMAGE PREVIEW ============ */
            inp?.addEventListener('change', function () {
                const f = this.files[0]; if (!f) return;
                if (f.size > 5 * 1024 * 1024) {alert('Image must be under 5MB'); this.value = ''; return;}
                const r = new FileReader();
                r.onload = e => {
                    pi.src = e.target.result;
                    pf.textContent = f.name;
                    ph.classList.add('hidden');
                    pv.classList.remove('hidden');
                };
                r.readAsDataURL(f);
            });

            ['dragenter', 'dragover'].forEach(ev => upZone?.addEventListener(ev, e => {e.preventDefault(); upZone.classList.add('dragover');}));
            ['dragleave', 'drop'].forEach(ev => upZone?.addEventListener(ev, e => {e.preventDefault(); upZone.classList.remove('dragover');}));
            upZone?.addEventListener('drop', e => {
                const files = e.dataTransfer?.files;
                if (files?.[0] && inp) {inp.files = files; inp.dispatchEvent(new Event('change'));}
            });

            /* ============================================================
               ★ AI DESCRIPTION GENERATOR
               ============================================================ */
            const aiBtn = document.getElementById('generateDescriptionBtn');
            const aiBtnText = document.getElementById('aiDescBtnText');
            const aiBtnIcon = document.getElementById('aiDescIcon');
            const aiStatus = document.getElementById('aiDescStatus');
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            const CSRF = csrfMeta ? csrfMeta.content : '';

            function setAiStatus(msg, type) {
                aiStatus.textContent = msg || '';
                aiStatus.className = 'text-[11px] mt-1.5 ' + (
                    type === 'ok' ? 'text-emerald-600 dark:text-emerald-400' :
                        type === 'err' ? 'text-rose-600 dark:text-rose-400' :
                            type === 'info' ? 'text-indigo-600 dark:text-indigo-400' :
                                'text-gray-500 dark:text-gray-400'
                );
                if (!msg) aiStatus.classList.add('hidden');
                else aiStatus.classList.remove('hidden');
            }

            aiBtn?.addEventListener('click', async function () {
                const title = (titleInput.value || '').trim();
                if (!title) {
                    setAiStatus('Pehle auction title likhein.', 'err');
                    titleInput.focus();
                    titleInput.scrollIntoView({behavior: 'smooth', block: 'center'});
                    return;
                }

                let categoryName = '';
                if (catInput && catInput.selectedIndex >= 0) {
                    const opt = catInput.options[catInput.selectedIndex];
                    if (opt && opt.value) categoryName = opt.textContent.trim();
                }

                const existing = (descInput.value || '').trim();

                aiBtn.disabled = true;
                const prevIconClass = aiBtnIcon.className;
                aiBtnIcon.className = 'fa-solid fa-spinner fa-spin text-[10px]';
                aiBtnText.textContent = 'Regenerating…';
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
                    try {data = await res.json();}
                    catch (parseErr) {data = {success: false, error: 'Invalid server response.'};}

                    if (!res.ok || !data.success) {
                        throw new Error(data.error || 'AI error. Please try again.');
                    }

                    descInput.value = data.description || '';
                    descInput.dispatchEvent(new Event('input', {bubbles: true}));

                    descInput.focus();
                    descInput.scrollIntoView({behavior: 'smooth', block: 'center'});

                    const rem = typeof data.remaining === 'number' ? ` · ${data.remaining} left this hour` : '';
                    setAiStatus('✅ Description ready! Aap edit kar sakte hain.' + rem, 'ok');

                } catch (err) {
                    setAiStatus('❌ ' + (err.message || 'Something went wrong.'), 'err');
                } finally {
                    aiBtn.disabled = false;
                    aiBtnIcon.className = prevIconClass || 'fa-solid fa-wand-magic-sparkles text-[10px]';
                    aiBtn.classList.remove('ai-pulsing');
                    aiBtnText.textContent = 'Regenerate with AI';
                }
            });

        })();
    </script>
    <?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>