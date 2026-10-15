<?php
session_start();
date_default_timezone_set("Asia/Karachi");
require_once "config/database.php";
require_once "includes/csrf.php";
require_once "includes/notifications.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: auth/login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: auctions.php");
    exit();
}

nb_csrf_check();

$user_id = intval($_SESSION["user_id"]);

/* =========================================================
   ★ KYC GATE — Buyer must be approved
========================================================= */
$kyc_chk = $conn->prepare("SELECT kyc_status FROM users WHERE user_id = ? LIMIT 1");
$kyc_chk->bind_param("i", $user_id);
$kyc_chk->execute();
$kyc_res = $kyc_chk->get_result()->fetch_assoc();
$kyc_chk->close();

if (($kyc_res['kyc_status'] ?? 'unverified') !== 'approved') {
    $_SESSION["bid_error"] = "Please complete KYC verification before bidding.";
    header("Location: user/kyc-status.php");
    exit();
}

$auction_id = intval($_POST["auction_id"] ?? 0);
$bid_amount = floatval($_POST["bid_amount"] ?? 0);

if ($auction_id <= 0 || $bid_amount <= 0) {
    $_SESSION["bid_error"] = "Please enter a valid bid amount.";
    header("Location: auctions.php");
    exit();
}

$stmt = $conn->prepare("
    SELECT a.*, c.category_name, u.full_name AS seller_name
    FROM auctions a
    INNER JOIN categories c ON a.category_id = c.category_id
    INNER JOIN users u ON a.seller_id = u.user_id
    WHERE a.auction_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $auction_id);
$stmt->execute();
$auction = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$auction) {
    $_SESSION["bid_error"] = "Auction not found.";
    header("Location: auctions.php");
    exit();
}

if (intval($auction["seller_id"]) === $user_id) {
    $_SESSION["bid_error"] = "You cannot place a bid on your own auction.";
    header("Location: auction-details.php?id=" . $auction_id);
    exit();
}

if ($auction["auction_status"] !== "active" || empty($auction["end_time"]) || strtotime($auction["end_time"]) <= time()) {
    $_SESSION["bid_error"] = "This auction is not currently active.";
    header("Location: auction-details.php?id=" . $auction_id);
    exit();
}

$current_bid = floatval($auction["current_bid"] ?? 0);
$starting_price = floatval($auction["starting_price"] ?? 0);
$bid_increment = floatval($auction["bid_increment"] ?? 0);
$display_price = $current_bid > 0 ? $current_bid : $starting_price;
$minimum_bid = $display_price + $bid_increment;

if ($bid_amount < $minimum_bid) {
    $_SESSION["bid_error"] = "Your bid must be at least Rs. " . number_format($minimum_bid);
    header("Location: auction-details.php?id=" . $auction_id);
    exit();
}

$rating_stmt = $conn->prepare("SELECT AVG(rating) AS avg_rating, COUNT(*) AS total FROM reviews WHERE seller_id = ?");
$rating_stmt->bind_param("i", $auction["seller_id"]);
$rating_stmt->execute();
$rating = $rating_stmt->get_result()->fetch_assoc();
$rating_stmt->close();
$seller_avg_rating = floatval($rating["avg_rating"] ?? 0);
$seller_review_count = intval($rating["total"] ?? 0);

$user_stmt = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ? LIMIT 1");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();
$user_name = $user["full_name"] ?? "Bidder";
$user_avatar = $user["avatar"] ?? "";
$user_initials = strtoupper(substr(preg_replace('/\s+/', '', $user_name), 0, 2));

$unread_notifications = nb_unread_count($conn, $user_id);

$count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM bids WHERE auction_id = ?");
$count_stmt->bind_param("i", $auction_id);
$count_stmt->execute();
$count_row = $count_stmt->get_result()->fetch_assoc();
$count_stmt->close();
$total_bids = intval($count_row["total"] ?? 0);

$escrow_deposit = round($bid_amount * 0.10, 2);
$buyer_premium = round($bid_amount * 0.015, 2);
$estimated_tax = 45000;
$estimated_total = $bid_amount + $buyer_premium + $estimated_tax;

$image = !empty($auction["image"]) ? "uploads/auctions/" . $auction["image"] : "";
$end_ts = strtotime($auction["end_time"]);
$seller_initials = strtoupper(substr(preg_replace('/\s+/', '', $auction["seller_name"]), 0, 2));
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Bid | NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <meta name="description" content="Confirm your bid and authorize the security deposit on NexusBids.">

    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif']},
                    colors: {brand: {50: '#eef2ff', 100: '#e0e7ff', 200: '#c7d2fe', 300: '#a5b4fc', 400: '#818cf8', 500: '#6366f1', 600: '#4f46e5', 700: '#4338ca', 800: '#3730a3', 900: '#312e81'}},
                    animation: {'fade-up': 'fadeUp 0.6s ease-out forwards', 'pulse-soft': 'pulseSoft 2s ease-in-out infinite'},
                    keyframes: {fadeUp: {'0%': {opacity: '0', transform: 'translateY(16px)'}, '100%': {opacity: '1', transform: 'translateY(0)'}}, pulseSoft: {'0%, 100%': {opacity: '1'}, '50%': {opacity: '0.5'}}}
                }
            }
        };
    </script>
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

        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .dark ::-webkit-scrollbar-thumb {
            background: #334155;
        }

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                animation-duration: .01ms !important;
                transition-duration: .01ms !important;
            }
        }

        .bid-amount-pulse {
            animation: bidAmountPulse 3s ease-in-out infinite;
        }

        @keyframes bidAmountPulse {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.01);
            }
        }
    </style>
</head>

<body
    class="bg-gray-50 text-gray-900 dark:bg-slate-950 dark:text-gray-100 font-sans antialiased min-h-screen flex flex-col">

    <!-- Top Bar -->
    <div class="bg-gray-950 text-gray-400 text-xs py-2.5 px-4 sm:px-8 border-b border-gray-800/50 relative z-40">
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-2">
            <div class="flex items-center space-x-6">
                <span class="flex items-center gap-2">
                    <span class="relative flex h-2 w-2">
                        <span
                            class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-400"></span>
                    </span>
                    <span class="font-medium text-gray-300">Secure Bidding Session</span>
                </span>
                <span class="hidden sm:flex items-center gap-2"><i
                        class="fa-solid fa-shield-halved text-indigo-400"></i> KYC Verified</span>
            </div>
            <div class="flex items-center space-x-6">
                <a href="about.php" class="hover:text-white transition-colors duration-200"><i
                        class="fa-regular fa-circle-question text-indigo-400"></i> Help Center</a>
            </div>
        </div>
    </div>

    <!-- Navbar -->
    <header
        class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
            <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight shrink-0" href="index.php">
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
                <a href="notifications.php"
                    class="relative w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($unread_notifications > 0): ?><span
                            class="absolute top-1.5 right-1.5 w-2.5 h-2.5 rounded-full bg-rose-500 border-2 border-white dark:border-gray-800"></span><?php endif; ?>
                </a>
                <div class="flex items-center gap-3 pl-3 border-l border-gray-200 dark:border-gray-800">
                    <div class="relative">
                        <?php if (!empty($user_avatar)): ?>
                            <img src="uploads/avatars/<?= htmlspecialchars($user_avatar) ?>"
                                class="w-10 h-10 rounded-full object-cover ring-2 ring-white dark:ring-slate-900" alt="">
                        <?php else: ?>
                            <div
                                class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center">
                                <?= htmlspecialchars($user_initials) ?></div>
                        <?php endif; ?>
                        <span
                            class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-emerald-500 border-2 border-white dark:border-slate-900"></span>
                    </div>
                    <div class="hidden sm:block text-left">
                        <div class="text-sm font-bold leading-tight truncate max-w-32">
                            <?= htmlspecialchars($user_name) ?></div>
                        <div
                            class="text-[11px] text-emerald-600 dark:text-emerald-400 font-medium flex items-center gap-1">
                            <i class="fa-solid fa-circle-check text-[8px]"></i> Verified Bidder
                        </div>
                    </div>
                </div>
                <a class="hidden sm:inline-flex px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:text-indigo-600 transition"
                    href="auth/logout.php">Logout</a>
            </div>
        </div>
    </header>

    <!-- Breadcrumb -->
    <section class="border-b border-gray-200 dark:border-gray-800 bg-white dark:bg-slate-900">
        <div
            class="max-w-7xl mx-auto px-4 sm:px-8 py-3 flex flex-col md:flex-row md:items-center justify-between gap-2">
            <nav class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 flex-wrap">
                <a class="hover:text-indigo-600 flex items-center gap-1.5" href="index.php"><i
                        class="fa-solid fa-house text-[10px]"></i> Home</a>
                <i class="fa-solid fa-chevron-right text-[8px]"></i>
                <a class="hover:text-indigo-600" href="auctions.php">Auctions</a>
                <i class="fa-solid fa-chevron-right text-[8px]"></i>
                <a class="hover:text-indigo-600 truncate max-w-[180px]"
                    href="auction-details.php?id=<?= $auction_id ?>"><?= htmlspecialchars($auction["title"]) ?></a>
                <i class="fa-solid fa-chevron-right text-[8px]"></i>
                <span class="text-indigo-600 dark:text-indigo-400 font-semibold">Confirm Bid</span>
            </nav>
            <span
                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-gray-100 dark:bg-slate-800 text-[10px] font-mono font-bold">
                <i class="fa-solid fa-hashtag text-[9px]"></i>
                LOT #<?= str_pad($auction_id, 5, '0', STR_PAD_LEFT) ?>
            </span>
        </div>
    </section>

    <main class="flex-grow max-w-7xl mx-auto w-full px-4 sm:px-8 py-8">

        <div class="mb-8 flex flex-col md:flex-row md:items-center md:justify-between gap-4 animate-fade-up">
            <div>
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span
                        class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-indigo-500/10 border border-indigo-500/20 text-indigo-600 dark:text-indigo-400 text-[11px] font-bold uppercase tracking-wider">
                        <i class="fa-solid fa-list-check text-[9px]"></i> Step 2 of 2 · Final Review
                    </span>
                    <span
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-[11px] font-bold uppercase tracking-wider">
                        <span class="relative flex h-2 w-2">
                            <span
                                class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                        </span>
                        Secure Session
                    </span>
                </div>
                <h1 class="text-2xl sm:text-3xl lg:text-4xl font-black tracking-tight">
                    Confirm Your <span class="gradient-text bg-gradient-to-r from-indigo-600 to-violet-600">Bid &amp;
                        Escrow</span>
                </h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2 max-w-2xl leading-relaxed">Review your bid and
                    authorize the standard 10% refundable security hold before submitting your binding bid.</p>
            </div>
            <a href="auction-details.php?id=<?= $auction_id ?>"
                class="group inline-flex items-center gap-2 text-xs font-semibold text-gray-600 dark:text-gray-300 bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 hover:border-indigo-500 px-4 py-2.5 rounded-xl shadow-sm transition-all self-start whitespace-nowrap">
                <i class="fa-solid fa-arrow-left text-[10px] group-hover:-translate-x-0.5"></i>
                Return to Auction
            </a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            <!-- LEFT: Summary -->
            <div class="lg:col-span-5 space-y-5 animate-fade-up">
                <div
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <div class="p-5">
                        <div class="flex flex-wrap items-center gap-2 mb-3">
                            <span
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 text-[10px] font-bold uppercase tracking-wider border border-emerald-500/20">
                                <span class="relative flex h-1.5 w-1.5">
                                    <span
                                        class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-emerald-500"></span>
                                </span>
                                Live Auction
                            </span>
                            <span
                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-indigo-500/10 text-indigo-700 dark:text-indigo-400 text-[10px] font-bold">
                                <i class="fa-solid fa-tag text-[8px]"></i>
                                <?= htmlspecialchars($auction["category_name"]) ?>
                            </span>
                        </div>
                        <h2 class="text-lg font-bold leading-snug"><?= htmlspecialchars($auction["title"]) ?></h2>
                        <div class="flex items-center gap-3 mt-2 text-xs text-gray-500">
                            <span class="flex items-center gap-1.5">
                                <span
                                    class="w-6 h-6 rounded-lg bg-gradient-to-br from-indigo-500 to-violet-500 text-white flex items-center justify-center text-[9px] font-bold"><?= htmlspecialchars($seller_initials) ?></span>
                                <?= htmlspecialchars($auction["seller_name"]) ?>
                            </span>
                            <span>·</span>
                            <span><i class="fa-solid fa-gavel text-[10px]"></i> <?= $total_bids ?> bids</span>
                        </div>
                    </div>
                    <div class="relative overflow-hidden bg-gray-100 dark:bg-slate-800 aspect-video mx-5 rounded-2xl">
                        <?php if ($image): ?>
                            <img src="<?= htmlspecialchars($image) ?>" class="w-full h-full object-cover" alt="">
                        <?php else: ?>
                            <div class="w-full h-full flex flex-col items-center justify-center text-gray-400">
                                <i class="fa-solid fa-image text-4xl mb-2"></i>
                                <span class="text-xs font-medium">No image</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="p-5">
                        <div class="rounded-2xl bg-slate-950 relative overflow-hidden">
                            <div class="relative p-4">
                                <div class="flex items-center justify-between mb-3">
                                    <span
                                        class="flex items-center gap-1.5 text-[11px] font-bold text-amber-400 uppercase tracking-wider">
                                        <i class="fa-regular fa-clock text-[10px]"></i> Closes In
                                    </span>
                                    <span class="text-[10px] text-gray-400">Highest valid bid wins</span>
                                </div>
                                <div id="timer"
                                    class="text-center text-2xl sm:text-3xl font-black text-white font-mono tabular-nums tracking-tight">
                                    --h : --m : --s</div>
                                <div
                                    class="mt-3 pt-3 border-t border-white/10 flex items-center justify-between text-[10px] text-gray-400">
                                    <span><?= date("d M Y, h:i A", $end_ts) ?></span>
                                    <span class="text-emerald-400 font-semibold">Live</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <div
                        class="p-5 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span
                                class="w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-calculator"></i></span>
                            <div>
                                <h3 class="text-sm font-bold">Escrow Deposit</h3>
                                <p class="text-[10px] text-gray-500 mt-0.5">10% refundable hold</p>
                            </div>
                        </div>
                        <span
                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20 text-[10px] font-bold uppercase tracking-wider">
                            <i class="fa-solid fa-shield-halved text-[9px]"></i> Refundable
                        </span>
                    </div>
                    <div class="p-5 space-y-2.5 text-xs">
                        <div class="flex justify-between py-1.5 border-b border-gray-100 dark:border-gray-800">
                            <span class="text-gray-500">Current leading bid</span>
                            <span class="font-semibold tabular-nums">Rs. <?= number_format($display_price) ?></span>
                        </div>
                        <div class="flex justify-between py-1.5 border-b border-gray-100 dark:border-gray-800">
                            <span class="font-semibold text-indigo-600 dark:text-indigo-400">Your proposed bid</span>
                            <span class="font-bold text-indigo-600 dark:text-indigo-400 tabular-nums">Rs.
                                <?= number_format($bid_amount) ?></span>
                        </div>
                        <div class="flex justify-between py-1.5 border-b border-gray-100 dark:border-gray-800">
                            <span class="text-gray-500">Security deposit (10%)</span>
                            <span class="font-bold tabular-nums">Rs. <?= number_format($escrow_deposit) ?></span>
                        </div>
                        <div
                            class="mt-4 p-3.5 rounded-xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 border border-indigo-500/20 flex items-center justify-between gap-3">
                            <div>
                                <div class="text-xs font-bold text-indigo-900 dark:text-indigo-300">Authorization Hold
                                </div>
                                <div class="text-[10px] text-indigo-700 dark:text-indigo-400 mt-0.5">10% of proposed bid
                                </div>
                            </div>
                            <div class="text-base font-black text-indigo-700 dark:text-indigo-400 tabular-nums">Rs.
                                <?= number_format($escrow_deposit) ?></div>
                        </div>
                    </div>
                </div>

                <div
                    class="relative rounded-3xl bg-slate-950 text-white p-5 overflow-hidden shadow-xl border border-gray-800">
                    <div class="relative flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div
                                class="w-11 h-11 rounded-xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-lg">
                                <i class="fa-solid fa-shield-halved"></i>
                            </div>
                            <div>
                                <div class="text-xs font-bold uppercase tracking-wider text-emerald-400">NexusBids
                                    Protection</div>
                                <div class="text-[11px] text-gray-300 mt-0.5">KYC Verified Bidder</div>
                            </div>
                        </div>
                        <span
                            class="text-xs font-mono font-bold bg-white/10 px-2.5 py-1 rounded-md text-emerald-300 border border-emerald-500/30">SECURE</span>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Confirmation Form -->
            <div class="lg:col-span-7 animate-fade-up">
                <form action="place-bid.php" method="POST"
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden"
                    onsubmit="return validateConfirmation();">
                    <?php nb_csrf_field(); ?>
                    <input type="hidden" name="auction_id" value="<?= $auction_id ?>">
                    <input type="hidden" name="bid_amount" value="<?= htmlspecialchars($bid_amount) ?>">

                    <div class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800">
                        <div class="flex items-center gap-3">
                            <span
                                class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm"><i
                                    class="fa-solid fa-lock"></i></span>
                            <div>
                                <h2 class="text-base font-bold">Authorize Escrow &amp; Place Binding Bid</h2>
                                <p class="text-xs text-gray-500 mt-0.5">Confirm your bid parameters before submitting.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="p-5 sm:p-6 space-y-6">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider mb-2.5">Your Maximum Bid
                                (PKR)</label>
                            <div
                                class="relative rounded-2xl border-2 border-indigo-500 bg-indigo-500/5 dark:bg-indigo-500/10 p-5 bid-amount-pulse">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div class="flex items-baseline gap-2">
                                        <span class="text-sm font-bold text-indigo-600 dark:text-indigo-400">Rs.</span>
                                        <span
                                            class="text-3xl sm:text-4xl font-black font-mono tracking-tight tabular-nums"><?= number_format($bid_amount) ?></span>
                                        <span class="text-xs font-bold text-gray-500">PKR</span>
                                    </div>
                                    <span
                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20 text-[11px] font-bold">
                                        <i class="fa-solid fa-circle-check text-[9px]"></i> Validated
                                    </span>
                                </div>
                            </div>
                            <div
                                class="flex flex-wrap items-center justify-between gap-2 mt-2.5 text-[11px] text-gray-500">
                                <span class="flex items-center gap-1.5">
                                    <i class="fa-solid fa-arrow-up text-indigo-500 text-[9px]"></i>
                                    Minimum: <strong class="text-gray-700 dark:text-gray-300 tabular-nums">Rs.
                                        <?= number_format($minimum_bid) ?></strong>
                                </span>
                                <span class="flex items-center gap-1.5">
                                    <i class="fa-solid fa-gavel text-gray-400 text-[9px]"></i>
                                    Current: <strong class="text-gray-700 dark:text-gray-300 tabular-nums">Rs.
                                        <?= number_format($display_price) ?></strong>
                                </span>
                            </div>
                        </div>

                        <div class="pt-2 border-t border-gray-100 dark:border-gray-800">
                            <h4
                                class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                                <i class="fa-solid fa-receipt text-[10px]"></i> Estimated Acquisition Cost
                            </h4>
                            <div
                                class="rounded-xl bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-gray-800 p-4 space-y-2.5 text-xs">
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Winning hammer bid</span>
                                    <span class="font-semibold tabular-nums">Rs.
                                        <?= number_format($bid_amount) ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Buyer's premium (1.5%)</span>
                                    <span class="font-semibold tabular-nums">Rs.
                                        <?= number_format($buyer_premium) ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Estimated transfer &amp; token tax</span>
                                    <span class="font-semibold tabular-nums">Rs.
                                        <?= number_format($estimated_tax) ?></span>
                                </div>
                                <div
                                    class="pt-2.5 mt-1 border-t border-gray-200 dark:border-gray-700 flex justify-between items-center">
                                    <span class="font-bold">Estimated Total</span>
                                    <span
                                        class="text-base font-black text-indigo-600 dark:text-indigo-400 tabular-nums">Rs.
                                        <?= number_format($estimated_total) ?></span>
                                </div>
                            </div>
                            <p class="text-[10px] text-gray-400 mt-2 text-center">Final amount may vary based on final
                                winning bid</p>
                        </div>

                        <div class="space-y-3 pt-2 border-t border-gray-100 dark:border-gray-800">
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input id="legal1" type="checkbox" required
                                    class="mt-0.5 h-4 w-4 text-indigo-600 rounded">
                                <span class="text-xs text-gray-600 dark:text-gray-400 leading-relaxed">I acknowledge
                                    that my bid of <strong class="text-gray-900 dark:text-white">Rs.
                                        <?= number_format($bid_amount) ?></strong> is submitted through the NexusBids
                                    auction system and is subject to its auction terms.</span>
                            </label>
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input id="legal2" type="checkbox" required
                                    class="mt-0.5 h-4 w-4 text-indigo-600 rounded">
                                <span class="text-xs text-gray-600 dark:text-gray-400 leading-relaxed">I understand the
                                    displayed security hold is a confirmation estimate and that the existing bidding
                                    backend remains responsible for processing the bid.</span>
                            </label>
                        </div>

                        <div class="space-y-3 pt-2">
                            <button type="submit"
                                class="group w-full py-4 px-6 rounded-2xl bg-gradient-to-r from-indigo-600 via-violet-600 to-purple-600 hover:from-indigo-500 hover:via-violet-500 hover:to-purple-500 text-white font-black text-base shadow-xl shadow-indigo-600/30 transition-all duration-300 hover:-translate-y-0.5 flex items-center justify-center gap-2">
                                <i class="fa-solid fa-lock group-hover:rotate-6 transition-transform"></i>
                                Confirm Bid — Rs. <?= number_format($bid_amount) ?>
                            </button>
                            <div class="text-center">
                                <a href="auction-details.php?id=<?= $auction_id ?>"
                                    class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 hover:text-gray-800 dark:hover:text-gray-200 transition-colors">
                                    <i class="fa-solid fa-xmark text-[10px]"></i>
                                    Cancel and return to auction
                                </a>
                            </div>
                        </div>

                        <div
                            class="pt-4 border-t border-gray-100 dark:border-gray-800 flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-[10px] text-gray-400 uppercase tracking-wider font-semibold">
                            <span class="flex items-center gap-1.5"><i class="fa-solid fa-lock text-emerald-500"></i>
                                Secure Session</span>
                            <span class="flex items-center gap-1.5"><i
                                    class="fa-solid fa-shield-halved text-indigo-500"></i> CSRF Protected</span>
                            <span class="flex items-center gap-1.5"><i
                                    class="fa-solid fa-circle-check text-blue-500"></i> KYC Verified</span>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <footer class="bg-slate-950 text-gray-400 text-xs border-t border-gray-900 mt-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-8 py-10 flex flex-col sm:flex-row items-center justify-between gap-4">
            <p>© <?php echo date("Y"); ?> NexusBids. All rights reserved.</p>
            <p>Made for Pakistan <span class="text-rose-500"><i class="fa-solid fa-heart"></i></span></p>
        </div>
    </footer>

    <script>
        (function () {
            const html = document.documentElement;
            const themeToggle = document.getElementById('themeToggle');
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) html.classList.add('dark');
            themeToggle?.addEventListener('click', function () {
                html.classList.toggle('dark');
                localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light');
            });

            const endTs = <?php echo $end_ts; ?> * 1000;
            const timerEl = document.getElementById('timer');
            function tick() {
                let d = Math.max(0, endTs - Date.now());
                let s = Math.floor(d / 1000);
                let h = Math.floor(s / 3600);
                s %= 3600;
                let m = Math.floor(s / 60);
                s %= 60;
                timerEl.textContent = String(h).padStart(2, '0') + 'h : ' + String(m).padStart(2, '0') + 'm : ' + String(s).padStart(2, '0') + 's';
                if (d <= 0) {timerEl.textContent = 'Auction Ended'; clearInterval(timerInterval);}
            }
            tick();
            const timerInterval = setInterval(tick, 1000);
        })();

        function validateConfirmation() {
            if (!document.getElementById('legal1').checked || !document.getElementById('legal2').checked) {
                alert('Please accept both confirmations before submitting your bid.');
                return false;
            }
            return true;
        }
    </script>
    <?php require_once __DIR__ . '/includes/chat-widget.php'; ?>
</body>

</html>