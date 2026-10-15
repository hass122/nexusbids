<?php
session_start();

// 1. Timezone Setup (Pakistan Standard Time)
date_default_timezone_set("Asia/Karachi");

require_once "config/database.php";
if (isset($conn)) {
    $conn->query("SET time_zone = '+05:00'");
}

require_once "includes/csrf.php";
require_once "includes/auto-bid.php";
require_once "includes/notifications.php";

$auction_id = isset($_GET["id"]) ? intval($_GET["id"]) : 0;

if ($auction_id <= 0) {
    header("Location: auctions.php");
    exit();
}

/* =======================================================
   ★ KYC STATUS FOR CURRENT USER
======================================================= */
$current_user_kyc_status = 'guest';
if (isset($_SESSION["user_id"])) {
    $k_stmt = $conn->prepare("SELECT kyc_status FROM users WHERE user_id = ? LIMIT 1");
    $k_stmt->bind_param("i", $_SESSION["user_id"]);
    $k_stmt->execute();
    $current_user_kyc_status = $k_stmt->get_result()->fetch_assoc()['kyc_status'] ?? 'unverified';
    $k_stmt->close();
}

/* =======================================================
   HELPER FUNCTION: FINALIZE AUCTION AND DECLARE WINNER
======================================================= */
function finalizeAuctionNow($conn, $auction_id)
{
    $bid_res = $conn->query("
        SELECT bid_id, bidder_id, bid_amount, bid_time 
        FROM bids 
        WHERE auction_id = {$auction_id} 
        ORDER BY bid_amount DESC, bid_time ASC 
        LIMIT 1
    ");

    if (!$bid_res) {
        $bid_res = $conn->query("
            SELECT bid_id, user_id AS bidder_id, bid_amount, bid_time 
            FROM bids 
            WHERE auction_id = {$auction_id} 
            ORDER BY bid_amount DESC, bid_time ASC 
            LIMIT 1
        ");
    }

    $highest_bid = ($bid_res && $bid_res->num_rows > 0) ? $bid_res->fetch_assoc() : null;

    if ($highest_bid) {
        $winner_id = intval($highest_bid["bidder_id"]);
        $winning_bid = floatval($highest_bid["bid_amount"]);

        $stmt = $conn->prepare("
            INSERT INTO winners (auction_id, user_id, winning_bid, status, created_at)
            VALUES (?, ?, ?, 'pending_payment', NOW())
            ON DUPLICATE KEY UPDATE winning_bid = VALUES(winning_bid)
        ");
        if ($stmt) {
            $stmt->bind_param("iid", $auction_id, $winner_id, $winning_bid);
            $stmt->execute();
            $stmt->close();
        }

        $conn->query("
            UPDATE auctions 
            SET auction_status = 'completed', current_bid = {$winning_bid} 
            WHERE auction_id = {$auction_id}
        ");

        if (function_exists("nb_notify")) {
            nb_notify($conn, $winner_id, "won", "You won an auction!", "Your winning bid was accepted.", "auction-details.php?id=" . $auction_id);
        }

        return ['status' => 'completed', 'winning_bid' => $winning_bid, 'winner_id' => $winner_id];
    } else {
        $conn->query("UPDATE auctions SET auction_status = 'ended' WHERE auction_id = {$auction_id}");
        return ['status' => 'ended'];
    }
}

/* =======================================================
   AUTOMATIC BACKGROUND TRIGGER (AJAX Call)
======================================================= */
if (isset($_GET['ajax_finalize'])) {
    header('Content-Type: application/json');
    $result = finalizeAuctionNow($conn, $auction_id);
    echo json_encode(['success' => true, 'data' => $result]);
    exit();
}

/* =======================================================
   GET AUCTION DATA
======================================================= */
$sql = "
    SELECT
        a.*,
        c.category_name,
        u.full_name AS seller_name,
        u.email AS seller_email,
        COUNT(DISTINCT b.bid_id) AS total_bids
    FROM auctions a
    INNER JOIN categories c ON a.category_id = c.category_id
    INNER JOIN users u ON a.seller_id = u.user_id
    LEFT JOIN bids b ON a.auction_id = b.auction_id
    WHERE a.auction_id = ?
    GROUP BY a.auction_id
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $auction_id);
$stmt->execute();
$result = $stmt->get_result();

if (!$result || $result->num_rows === 0) {
    header("Location: auctions.php");
    exit();
}

$auction = $result->fetch_assoc();

/* =======================================================
   SERVER-SIDE CHECK: AUTO-FINALIZE IF TIME HAS PASSED
======================================================= */
$now_ts = time();
$end_ts = strtotime($auction["end_time"]);
$is_ended = ($end_ts <= $now_ts);

if ($is_ended && in_array($auction["auction_status"], ['active', 'approved'])) {
    $res = finalizeAuctionNow($conn, $auction_id);
    $auction["auction_status"] = $res["status"];
    if (isset($res["winning_bid"])) {
        $auction["current_bid"] = $res["winning_bid"];
    }
}

/* =======================================================
   GET WINNER DETAILS (IF COMPLETED)
======================================================= */
$winner = null;
if ($auction["auction_status"] === "completed") {
    $winner_stmt = $conn->prepare("
        SELECT w.winning_bid, u.full_name, u.email
        FROM winners w
        INNER JOIN users u ON w.user_id = u.user_id
        WHERE w.auction_id = ?
        ORDER BY w.created_at DESC
        LIMIT 1
    ");
    if ($winner_stmt) {
        $winner_stmt->bind_param("i", $auction_id);
        $winner_stmt->execute();
        $winner = $winner_stmt->get_result()->fetch_assoc();
        $winner_stmt->close();
    }
}

/* =======================================================
   SELLER RATING & BID CALCULATIONS
======================================================= */
$rating_stmt = $conn->prepare("SELECT AVG(rating) AS avg_rating, COUNT(*) AS total FROM reviews WHERE seller_id = ?");
$rating_stmt->bind_param("i", $auction["seller_id"]);
$rating_stmt->execute();
$rating_row = $rating_stmt->get_result()->fetch_assoc();
$seller_avg_rating = floatval($rating_row["avg_rating"] ?? 0);
$seller_review_count = intval($rating_row["total"] ?? 0);
$rating_stmt->close();

$current_bid = floatval($auction["current_bid"]);
$starting_price = floatval($auction["starting_price"]);
$bid_increment = floatval($auction["bid_increment"]);
$display_price = $current_bid > 0 ? $current_bid : $starting_price;
$minimum_bid = $display_price + $bid_increment;

/* =======================================================
   WATCHLIST STATUS
======================================================= */
$is_watchlisted = false;
if (isset($_SESSION["user_id"])) {
    $uid = intval($_SESSION["user_id"]);
    $w_chk = $conn->query("SELECT watchlist_id FROM watchlist WHERE user_id = {$uid} AND auction_id = {$auction_id} LIMIT 1");
    $is_watchlisted = ($w_chk && $w_chk->num_rows > 0);
}

$unread_notifications = isset($_SESSION["user_id"])
    ? nb_unread_count($conn, intval($_SESSION["user_id"]))
    : 0;

/* =======================================================
   GET BID HISTORY
======================================================= */
$bid_history = $conn->query("
    SELECT b.bid_amount, b.bid_time, u.full_name
    FROM bids b
    INNER JOIN users u ON b.bidder_id = u.user_id
    WHERE b.auction_id = {$auction_id}
    ORDER BY b.bid_amount DESC, b.bid_time DESC
");
if (!$bid_history) {
    $bid_history = $conn->query("
        SELECT b.bid_amount, b.bid_time, u.full_name
        FROM bids b
        INNER JOIN users u ON b.user_id = u.user_id
        WHERE b.auction_id = {$auction_id}
        ORDER BY b.bid_amount DESC, b.bid_time DESC
    ");
}

$bid_history_data = [];
if ($bid_history && $bid_history->num_rows > 0) {
    while ($row = $bid_history->fetch_assoc())
        $bid_history_data[] = $row;
}
$total_bid_records = count($bid_history_data);

/* =======================================================
   RELATED AUCTIONS (same category)
======================================================= */
$related_stmt = $conn->prepare("
    SELECT a.auction_id, a.title, a.image, a.current_bid, a.starting_price, a.end_time, a.auction_status
    FROM auctions a
    WHERE a.category_id = (SELECT category_id FROM auctions WHERE auction_id = ?)
      AND a.auction_id != ?
      AND a.auction_status = 'active'
      AND a.end_time > NOW()
    ORDER BY a.end_time ASC
    LIMIT 4
");
$related_stmt->bind_param("ii", $auction_id, $auction_id);
$related_stmt->execute();
$related_result = $related_stmt->get_result();
$related_auctions = [];
while ($row = $related_result->fetch_assoc())
    $related_auctions[] = $row;

?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($auction["title"]); ?> | NexusBids Live Auction</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <meta name="description"
        content="<?php echo htmlspecialchars(substr(strip_tags($auction['description'] ?? ''), 0, 155)); ?>">

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
                        'shimmer': 'shimmer 2s linear infinite',
                    },
                    keyframes: {
                        fadeUp: {'0%': {opacity: '0', transform: 'translateY(16px)'}, '100%': {opacity: '1', transform: 'translateY(0)'}},
                        fadeIn: {'0%': {opacity: '0'}, '100%': {opacity: '1'}},
                        pulseSoft: {'0%, 100%': {opacity: '1'}, '50%': {opacity: '0.5'}},
                        float: {'0%, 100%': {transform: 'translateY(0)'}, '50%': {transform: 'translateY(-10px)'}},
                        shimmer: {'0%': {backgroundPosition: '-1000px 0'}, '100%': {backgroundPosition: '1000px 0'}},
                    }
                }
            }
        }
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

        .noise-overlay::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 1;
        }

        .custom-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L2 4h8z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            appearance: none;
        }

        .countdown-seconds-pulse {
            animation: countdownPulse 1s ease-in-out infinite;
        }

        @keyframes countdownPulse {

            0%,
            100% {
                box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.4);
            }

            50% {
                box-shadow: 0 0 0 8px rgba(99, 102, 241, 0);
            }
        }

        @media (min-width: 1024px) {
            .bid-panel-sticky {
                position: sticky;
                top: 6rem;
            }
        }
    </style>
</head>

<body
    class="bg-gray-50 text-gray-900 dark:bg-slate-950 dark:text-gray-100 font-sans antialiased selection:bg-indigo-500 selection:text-white min-h-screen flex flex-col">

    <!-- Top Utility Bar -->
    <div class="bg-gray-950 text-gray-400 text-xs py-2.5 px-4 sm:px-8 border-b border-gray-800/50 relative z-40">
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-2">
            <div class="flex items-center space-x-6">
                <span class="flex items-center gap-2">
                    <span class="relative flex h-2 w-2">
                        <span
                            class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-400"></span>
                    </span>
                    <span class="font-medium text-gray-300">Live Network</span>
                </span>
                <a href="tel:+923231328558"
                    class="hidden sm:flex items-center gap-2 hover:text-white transition-colors duration-200">
                    <i class="fa-solid fa-phone text-indigo-400"></i> +92 323 1328558
                </a>
            </div>
            <div class="flex items-center space-x-6">
                <a href="about.php" class="hover:text-white transition-colors duration-200"><i
                        class="fa-regular fa-circle-question text-indigo-400"></i> Help Center</a>
                <a href="about.php#why-nexusbids"
                    class="hover:text-white transition-colors duration-200 flex items-center gap-1.5"><i
                        class="fa-solid fa-shield-halved text-emerald-400"></i> Secure Bidding</a>
            </div>
        </div>
    </div>

    <!-- Main Navbar -->
    <header
        class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
            <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight text-gray-900 dark:text-white shrink-0 group"
                href="index.php">
                <span
                    class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30 group-hover:shadow-indigo-500/50 group-hover:scale-105 transition-all duration-300">
                    <i class="fa-solid fa-gavel text-lg"></i>
                </span>
                Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
            </a>

            <nav class="hidden lg:flex items-center gap-8 text-sm font-medium">
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
                    href="index.php">Home</a>
                <a class="relative text-indigo-600 dark:text-indigo-400 font-semibold after:absolute after:-bottom-1 after:left-0 after:w-full after:h-0.5 after:bg-indigo-600 dark:after:bg-indigo-400 after:rounded-full flex items-center gap-2"
                    href="auctions.php">
                    Auctions
                    <span class="relative flex h-1.5 w-1.5">
                        <span
                            class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-rose-500"></span>
                    </span>
                </a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
                    href="categories.php">Categories</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
                    href="index.php#how-it-works">How It Works</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
                    href="about.php">About</a>
            </nav>

            <div class="flex items-center gap-2 sm:gap-3">
                <button aria-label="Toggle Theme"
                    class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-all duration-200 hover:scale-105"
                    id="themeToggle">
                    <i class="fa-solid fa-moon dark:hidden"></i><i
                        class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                </button>

                <a href="<?php echo isset($_SESSION["user_id"]) ? 'user/watchlist.php' : 'auth/login.php'; ?>"
                    class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-rose-50 dark:hover:bg-rose-950/30 hover:text-rose-500 transition-all duration-200 hover:scale-105"
                    title="Watchlist">
                    <i class="fa-solid fa-heart"></i>
                </a>

                <?php if (isset($_SESSION["user_id"])): ?>
                    <a href="notifications.php"
                        class="relative w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-all duration-200 hover:scale-105"
                        title="Notifications">
                        <i class="fa-regular fa-bell"></i>
                        <?php if ($unread_notifications > 0): ?><span
                                class="absolute top-1.5 right-1.5 w-2.5 h-2.5 rounded-full bg-rose-500 border-2 border-white dark:border-gray-800 animate-pulse-soft"></span><?php endif; ?>
                    </a>
                    <a class="hidden sm:inline-flex px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition-all duration-200 hover:shadow-lg hover:shadow-indigo-600/30"
                        href="<?php echo ($_SESSION['role'] ?? '') === 'seller' ? 'seller/dashboard.php' : 'user/dashboard.php'; ?>">
                        <i class="fa-solid fa-user mr-1.5"></i> Dashboard
                    </a>
                <?php else: ?>
                    <a href="auth/login.php"
                        class="hidden sm:inline-flex px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">Login</a>
                    <a href="auth/register.php"
                        class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition-all duration-200 hover:shadow-lg hover:shadow-indigo-600/30">Register</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="lg:hidden border-t border-gray-100 dark:border-gray-800 px-4 py-3 overflow-x-auto">
            <div class="flex items-center gap-5 text-sm whitespace-nowrap">
                <a class="text-gray-600 dark:text-gray-300" href="index.php">Home</a>
                <a class="text-indigo-600 dark:text-indigo-400 font-semibold" href="auctions.php">Auctions</a>
                <a class="text-gray-600 dark:text-gray-300" href="categories.php">Categories</a>
                <a class="text-gray-600 dark:text-gray-300" href="about.php">About</a>
            </div>
        </div>
    </header>

    <!-- Flash Messages -->
    <?php
    $flash_types = [
        'bid_success' => ['icon' => 'fa-circle-check', 'bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-300', 'border' => 'border-emerald-500/30', 'icon_color' => 'text-emerald-600 dark:text-emerald-400', 'icon_bg' => 'bg-emerald-500/20'],
        'watchlist_success' => ['icon' => 'fa-heart', 'bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-300', 'border' => 'border-emerald-500/30', 'icon_color' => 'text-emerald-600 dark:text-emerald-400', 'icon_bg' => 'bg-emerald-500/20'],
        'bid_error' => ['icon' => 'fa-circle-exclamation', 'bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-300', 'border' => 'border-rose-500/30', 'icon_color' => 'text-rose-600 dark:text-rose-400', 'icon_bg' => 'bg-rose-500/20'],
        'watchlist_error' => ['icon' => 'fa-circle-xmark', 'bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-300', 'border' => 'border-rose-500/30', 'icon_color' => 'text-rose-600 dark:text-rose-400', 'icon_bg' => 'bg-rose-500/20'],
        'watchlist_info' => ['icon' => 'fa-circle-info', 'bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-300', 'border' => 'border-blue-500/30', 'icon_color' => 'text-blue-600 dark:text-blue-400', 'icon_bg' => 'bg-blue-500/20'],
    ];
    foreach ($flash_types as $key => $style):
        if (isset($_SESSION[$key])): ?>
            <div class="max-w-7xl mx-auto w-full px-4 sm:px-8 pt-4">
                <div
                    class="flex items-start gap-3 p-4 rounded-2xl <?php echo $style['bg']; ?> border <?php echo $style['border']; ?> animate-fade-up">
                    <div
                        class="w-9 h-9 rounded-lg <?php echo $style['icon_bg']; ?> <?php echo $style['icon_color']; ?> flex items-center justify-center shrink-0">
                        <i class="fa-solid <?php echo $style['icon']; ?>"></i>
                    </div>
                    <p class="text-sm <?php echo $style['text']; ?> font-medium self-center">
                        <?php echo htmlspecialchars($_SESSION[$key]);
                        unset($_SESSION[$key]); ?>
                    </p>
                </div>
            </div>
        <?php endif;
    endforeach;
    ?>

    <!-- Breadcrumb -->
    <section class="border-b border-gray-200 dark:border-gray-800 bg-white dark:bg-slate-900">
        <div class="max-w-7xl mx-auto px-4 sm:px-8 py-3">
            <nav aria-label="Breadcrumb" class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <a class="hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors flex items-center gap-1.5"
                    href="index.php">
                    <i class="fa-solid fa-house text-[10px]"></i> Home
                </a>
                <i class="fa-solid fa-chevron-right text-[8px] text-gray-400"></i>
                <a class="hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors"
                    href="auctions.php">Auctions</a>
                <i class="fa-solid fa-chevron-right text-[8px] text-gray-400"></i>
                <span
                    class="text-gray-900 dark:text-white font-semibold truncate max-w-[180px] sm:max-w-md"><?php echo htmlspecialchars($auction["title"]); ?></span>
            </nav>
        </div>
    </section>

    <main class="flex-grow max-w-7xl mx-auto w-full px-4 sm:px-8 py-8">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">

            <!-- LEFT COLUMN -->
            <div class="lg:col-span-7 space-y-6">

                <!-- Product Gallery -->
                <div
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden animate-fade-up">
                    <div class="relative aspect-[4/3] overflow-hidden bg-gray-100 dark:bg-slate-800 group">
                        <?php if (!empty($auction["image"])): ?>
                            <img id="main-product-img"
                                src="uploads/auctions/<?php echo htmlspecialchars($auction["image"]); ?>"
                                alt="<?php echo htmlspecialchars($auction["title"]); ?>"
                                class="w-full h-full object-cover object-center group-hover:scale-105 transition-transform duration-700">
                        <?php else: ?>
                            <div class="w-full h-full flex flex-col items-center justify-center text-gray-400">
                                <i class="fa-solid fa-image text-5xl mb-3"></i>
                                <p class="text-sm font-medium">No Image Available</p>
                            </div>
                        <?php endif; ?>

                        <div
                            class="absolute inset-0 bg-gradient-to-t from-black/40 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                        </div>

                        <div class="absolute top-4 left-4 flex flex-wrap gap-2">
                            <?php if (!$is_ended && $auction["auction_status"] !== "completed"): ?>
                                <span
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold tracking-wide uppercase bg-emerald-500 text-white shadow-lg backdrop-blur">
                                    <span class="relative flex h-2 w-2">
                                        <span
                                            class="animate-ping absolute inline-flex h-full w-full rounded-full bg-white opacity-75"></span>
                                        <span class="relative inline-flex rounded-full h-2 w-2 bg-white"></span>
                                    </span>
                                    Active
                                </span>
                            <?php else: ?>
                                <span
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold tracking-wide uppercase bg-gray-700 text-white shadow-lg">
                                    <i class="fa-solid fa-flag-checkered text-[9px]"></i> Closed
                                </span>
                            <?php endif; ?>
                            <span
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-white/95 dark:bg-slate-950/95 glass text-gray-800 dark:text-gray-100 backdrop-blur shadow-lg border border-white/20">
                                <i
                                    class="fa-solid fa-check-circle text-indigo-600 dark:text-indigo-400 text-[10px]"></i>
                                Verified
                            </span>
                        </div>

                        <div class="absolute top-4 right-4">
                            <?php if (isset($_SESSION["user_id"])): ?>
                                <form action="add-watchlist.php" method="POST">
                                    <?php nb_csrf_field(); ?>
                                    <input type="hidden" name="auction_id" value="<?php echo $auction_id; ?>">
                                    <button type="submit"
                                        title="<?php echo $is_watchlisted ? 'Already in watchlist' : 'Save to favorites'; ?>"
                                        class="group/heart w-11 h-11 rounded-xl bg-white/95 dark:bg-slate-950/95 glass text-gray-700 dark:text-gray-200 hover:text-rose-500 flex items-center justify-center shadow-lg transition-all duration-200 hover:scale-110">
                                        <i
                                            class="fa-<?php echo $is_watchlisted ? 'solid' : 'regular'; ?> fa-heart text-lg <?php echo $is_watchlisted ? 'text-rose-500' : ''; ?> group-hover/heart:scale-110 transition-transform"></i>
                                    </button>
                                </form>
                            <?php else: ?>
                                <a href="auth/login.php" title="Login to save"
                                    class="w-11 h-11 rounded-xl bg-white/95 dark:bg-slate-950/95 glass text-gray-700 dark:text-gray-200 hover:text-rose-500 flex items-center justify-center shadow-lg transition-all duration-200 hover:scale-110">
                                    <i class="fa-regular fa-heart text-lg"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Item Info -->
                <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden animate-fade-up"
                    style="animation-delay: 0.05s">
                    <div class="p-6 space-y-5">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <span
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold text-indigo-700 dark:text-indigo-300 bg-indigo-50 dark:bg-indigo-950/40 border border-indigo-100 dark:border-indigo-900/50">
                                <i class="fa-solid fa-tag text-[10px]"></i>
                                <?php echo htmlspecialchars($auction["category_name"]); ?>
                            </span>
                            <span class="text-xs text-gray-500 dark:text-gray-400 font-medium">
                                Lot Reference: <strong
                                    class="text-gray-800 dark:text-gray-200 font-mono">#<?php echo str_pad($auction_id, 5, '0', STR_PAD_LEFT); ?></strong>
                            </span>
                        </div>

                        <div>
                            <h1
                                class="text-2xl sm:text-3xl lg:text-4xl font-black tracking-tight text-gray-900 dark:text-white leading-snug">
                                <?php echo htmlspecialchars($auction["title"]); ?>
                            </h1>
                            <p class="text-gray-500 dark:text-gray-400 text-sm mt-3 leading-relaxed">Live auction
                                listing on NexusBids. Review the item details carefully and place your winning bid
                                before the closing time.</p>
                        </div>
                    </div>

                    <div
                        class="p-5 sm:p-6 bg-gray-50 dark:bg-slate-800/50 border-t border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div class="flex items-center gap-3.5">
                            <div class="relative shrink-0">
                                <div
                                    class="w-12 h-12 rounded-full bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white font-bold text-sm shadow-lg shadow-indigo-500/30">
                                    <?php echo htmlspecialchars(strtoupper(substr($auction["seller_name"], 0, 2))); ?>
                                </div>
                                <div
                                    class="absolute -bottom-0.5 -right-0.5 w-4 h-4 bg-emerald-500 rounded-full border-2 border-gray-50 dark:border-slate-800 flex items-center justify-center">
                                    <i class="fa-solid fa-check text-white text-[8px]"></i>
                                </div>
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span
                                        class="text-[11px] text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wider">Seller</span>
                                    <span
                                        class="text-[10px] px-2 py-0.5 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 font-bold rounded-full border border-emerald-500/20">Verified</span>
                                </div>
                                <h2 class="text-base font-bold text-gray-900 dark:text-white leading-tight mt-0.5">
                                    <?php echo htmlspecialchars($auction["seller_name"]); ?></h2>
                                <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    <?php if ($seller_review_count > 0): ?>
                                        <span class="flex items-center text-amber-500 font-bold">
                                            <i class="fa-solid fa-star text-[10px] mr-0.5"></i>
                                            <?php echo number_format($seller_avg_rating, 1); ?>
                                        </span>
                                        <span>·</span>
                                        <span><?php echo $seller_review_count; ?>
                                            review<?php echo $seller_review_count == 1 ? '' : 's'; ?></span>
                                    <?php else: ?>
                                        <span>No reviews yet</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <a href="about.php"
                            class="px-4 py-2 text-xs font-semibold text-indigo-700 dark:text-indigo-300 bg-indigo-50 dark:bg-indigo-950/40 hover:bg-indigo-100 dark:hover:bg-indigo-950/60 rounded-lg transition-colors duration-200 flex items-center gap-1.5 whitespace-nowrap">
                            <i class="fa-solid fa-circle-info text-[10px]"></i>
                            Buyer Info
                        </a>
                    </div>
                </div>

                <!-- Description & Details -->
                <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden animate-fade-up"
                    style="animation-delay: 0.1s">
                    <div class="p-6 border-b border-gray-100 dark:border-gray-800">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2.5 mb-4">
                            <span
                                class="w-9 h-9 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm">
                                <i class="fa-solid fa-file-lines"></i>
                            </span>
                            Item Description
                        </h3>
                        <div class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed">
                            <p><?php echo nl2br(htmlspecialchars($auction["description"])); ?></p>
                        </div>
                    </div>

                    <div class="p-6">
                        <h4 class="text-xs font-bold text-gray-500 dark:text-gray-400 mb-4 uppercase tracking-wider">
                            Auction Details</h4>
                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-0">
                            <div
                                class="flex justify-between items-center py-3 border-b border-gray-100 dark:border-gray-800">
                                <dt class="text-sm text-gray-500 dark:text-gray-400">Starting Price</dt>
                                <dd class="text-sm font-bold text-gray-900 dark:text-white tabular-nums">Rs.
                                    <?php echo number_format($starting_price); ?></dd>
                            </div>
                            <div
                                class="flex justify-between items-center py-3 border-b border-gray-100 dark:border-gray-800">
                                <dt class="text-sm text-gray-500 dark:text-gray-400">Bid Increment</dt>
                                <dd class="text-sm font-bold text-gray-900 dark:text-white tabular-nums">Rs.
                                    <?php echo number_format($bid_increment); ?></dd>
                            </div>
                            <div
                                class="flex justify-between items-center py-3 border-b border-gray-100 dark:border-gray-800">
                                <dt class="text-sm text-gray-500 dark:text-gray-400">Current Bid</dt>
                                <dd class="text-sm font-bold text-indigo-600 dark:text-indigo-400 tabular-nums">Rs.
                                    <?php echo number_format($display_price); ?></dd>
                            </div>
                            <div
                                class="flex justify-between items-center py-3 border-b border-gray-100 dark:border-gray-800">
                                <dt class="text-sm text-gray-500 dark:text-gray-400">Total Bids</dt>
                                <dd class="text-sm font-bold text-gray-900 dark:text-white tabular-nums"><span
                                        id="live-stat-total-bids"><?php echo intval($auction["total_bids"]); ?></span>
                                </dd>
                            </div>
                            <div
                                class="flex justify-between items-center py-3 border-b border-gray-100 dark:border-gray-800">
                                <dt class="text-sm text-gray-500 dark:text-gray-400">Start Date</dt>
                                <dd class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                    <?php echo date("d M Y", strtotime($auction["start_time"])); ?></dd>
                            </div>
                            <div
                                class="flex justify-between items-center py-3 border-b border-gray-100 dark:border-gray-800">
                                <dt class="text-sm text-gray-500 dark:text-gray-400">End Date</dt>
                                <dd class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                    <?php echo date("d M Y, h:i A", strtotime($auction["end_time"])); ?></dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <!-- Bid History -->
                <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden animate-fade-up"
                    style="animation-delay: 0.15s">
                    <div
                        class="p-6 border-b border-gray-100 dark:border-gray-800 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2.5">
                            <span
                                class="w-9 h-9 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                            </span>
                            Live Bid History
                        </h3>
                        <span
                            class="text-[11px] px-2.5 py-1 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 font-bold rounded-full flex items-center gap-1.5 border border-emerald-500/20">
                            <span class="relative flex h-1.5 w-1.5">
                                <span
                                    class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-emerald-500"></span>
                            </span>
                            Live Sync
                        </span>
                    </div>

                    <?php if ($total_bid_records > 0): ?>
                        <div id="live-bid-history-list"
                            class="divide-y divide-gray-100 dark:divide-gray-800 max-h-[28rem] overflow-y-auto">
                            <?php foreach ($bid_history_data as $index => $bid): ?>
                                <div
                                    class="flex items-center justify-between gap-4 p-5 hover:bg-gray-50 dark:hover:bg-slate-800/50 transition-colors duration-200 <?php echo $index === 0 ? 'bg-emerald-50/50 dark:bg-emerald-950/20' : ''; ?>">
                                    <div class="flex items-center gap-3.5 min-w-0">
                                        <div class="relative shrink-0">
                                            <div
                                                class="w-11 h-11 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center shadow-lg shadow-indigo-500/20">
                                                <?php echo htmlspecialchars(strtoupper(substr($bid["full_name"], 0, 2))); ?>
                                            </div>
                                            <?php if ($index === 0): ?>
                                                <span
                                                    class="absolute -top-1 -right-1 w-5 h-5 rounded-full bg-emerald-500 border-2 border-white dark:border-slate-900 flex items-center justify-center">
                                                    <i class="fa-solid fa-crown text-white text-[8px]"></i>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <strong
                                                    class="text-sm font-bold text-gray-900 dark:text-white truncate"><?php echo htmlspecialchars($bid["full_name"]); ?></strong>
                                                <?php if ($index === 0): ?>
                                                    <span
                                                        class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20">Highest</span>
                                                <?php endif; ?>
                                            </div>
                                            <div
                                                class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 flex items-center gap-1.5">
                                                <i class="fa-regular fa-clock text-[10px]"></i>
                                                <?php echo date("d M Y, h:i A", strtotime($bid["bid_time"])); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <strong
                                        class="text-base sm:text-lg font-black text-gray-900 dark:text-white tabular-nums whitespace-nowrap">
                                        Rs. <?php echo number_format($bid["bid_amount"]); ?>
                                    </strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="p-10 text-center">
                            <div
                                class="w-16 h-16 rounded-2xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 text-indigo-500 flex items-center justify-center text-2xl mx-auto mb-4">
                                <i class="fa-solid fa-bolt"></i>
                            </div>
                            <h4 class="text-base font-bold text-gray-900 dark:text-white">No Bids Placed Yet</h4>
                            <p class="text-gray-500 dark:text-gray-400 text-sm max-w-sm mx-auto mt-2 leading-relaxed">
                                Be the first bidder! Place an opening bid of at least <strong
                                    class="text-gray-700 dark:text-gray-200">Rs.
                                    <?php echo number_format($minimum_bid); ?></strong>.
                            </p>
                            <div
                                class="mt-5 inline-flex items-center gap-2 text-xs font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 px-3.5 py-2 rounded-lg">
                                <i class="fa-solid fa-shield-halved"></i>
                                Escrow-Protected Bidding
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Related Auctions -->
                <?php if (!empty($related_auctions)): ?>
                    <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden animate-fade-up"
                        style="animation-delay: 0.2s">
                        <div class="p-6 border-b border-gray-100 dark:border-gray-800">
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2.5">
                                <span
                                    class="w-9 h-9 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm">
                                    <i class="fa-solid fa-fire"></i>
                                </span>
                                Similar Live Auctions
                            </h3>
                        </div>
                        <div class="p-6 grid grid-cols-2 sm:grid-cols-4 gap-4">
                            <?php foreach ($related_auctions as $rel):
                                $rel_price = floatval($rel["current_bid"]) > 0 ? floatval($rel["current_bid"]) : floatval($rel["starting_price"]);
                                ?>
                                <a href="auction-details.php?id=<?php echo (int) $rel["auction_id"]; ?>"
                                    class="group rounded-2xl border border-gray-200 dark:border-gray-800 hover:border-indigo-500 dark:hover:border-indigo-500 overflow-hidden bg-white dark:bg-slate-900 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg hover:shadow-indigo-500/10">
                                    <div class="h-24 bg-gray-100 dark:bg-slate-800 overflow-hidden">
                                        <?php if (!empty($rel["image"])): ?>
                                            <img src="uploads/auctions/<?php echo htmlspecialchars($rel["image"]); ?>"
                                                alt="<?php echo htmlspecialchars($rel["title"]); ?>"
                                                class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500">
                                        <?php else: ?>
                                            <div class="w-full h-full flex items-center justify-center text-gray-400">
                                                <i class="fa-solid fa-image"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="p-3">
                                        <h4
                                            class="text-xs font-bold text-gray-900 dark:text-white line-clamp-2 leading-snug group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors min-h-8">
                                            <?php echo htmlspecialchars($rel["title"]); ?></h4>
                                        <div
                                            class="text-[11px] font-black text-indigo-600 dark:text-indigo-400 mt-2 tabular-nums">
                                            Rs. <?php echo number_format($rel_price); ?></div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- RIGHT COLUMN - BIDDING PANEL -->
            <div class="lg:col-span-5 space-y-6 bid-panel-sticky animate-fade-up" style="animation-delay: 0.05s">

                <!-- Bidding Panel -->
                <div
                    class="relative bg-white dark:bg-slate-900 rounded-3xl border-2 border-indigo-500/30 dark:border-indigo-500/40 shadow-xl shadow-indigo-500/10 overflow-hidden">
                    <div
                        class="absolute -right-16 -top-16 w-48 h-48 bg-indigo-500/20 rounded-full blur-3xl pointer-events-none">
                    </div>
                    <div
                        class="absolute -left-16 -bottom-16 w-48 h-48 bg-violet-500/20 rounded-full blur-3xl pointer-events-none">
                    </div>

                    <div class="relative">
                        <!-- Price Header -->
                        <div
                            class="p-6 border-b border-gray-100 dark:border-gray-800 flex items-start justify-between gap-4">
                            <div>
                                <span
                                    class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 flex items-center gap-1.5">
                                    <span class="relative flex h-2 w-2">
                                        <span
                                            class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                                    </span>
                                    Current Highest Bid
                                </span>
                                <div class="flex items-baseline gap-2 mt-2">
                                    <span id="live-current-price"
                                        class="text-4xl font-black text-gray-900 dark:text-white tracking-tight tabular-nums">
                                        Rs. <?php echo number_format($display_price); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <span
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-gray-100 dark:bg-slate-800 text-gray-700 dark:text-gray-300">
                                    <i class="fa-solid fa-gavel text-[10px] text-indigo-500"></i>
                                    <span id="live-bid-count"><?php echo intval($auction["total_bids"]); ?></span> Bids
                                </span>
                                <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-1.5 font-medium">
                                    <?php echo $current_bid > 0 ? "Reserve Active" : "Opening Bid"; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Winner Area -->
                        <div id="winner-announcement-area">
                            <?php if ($auction["auction_status"] === "completed" && $winner): ?>
                                <div
                                    class="m-6 p-6 rounded-2xl bg-gradient-to-br from-amber-500/10 to-orange-500/10 border-2 border-amber-500/40 text-center relative overflow-hidden">
                                    <div class="absolute inset-0 opacity-30"
                                        style="background-image: radial-gradient(circle at 50% 50%, rgba(251, 191, 36, 0.2), transparent 70%);">
                                    </div>
                                    <div class="relative">
                                        <div
                                            class="w-14 h-14 rounded-full bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center text-white text-2xl mx-auto mb-3 shadow-lg shadow-amber-500/30">
                                            <i class="fa-solid fa-trophy"></i>
                                        </div>
                                        <h3 class="font-black text-amber-900 dark:text-amber-300 text-lg">Auction Winner!
                                        </h3>
                                        <p class="text-sm text-gray-700 dark:text-gray-300 mt-1.5">
                                            Congratulations,
                                            <strong><?php echo htmlspecialchars($winner["full_name"]); ?></strong>
                                        </p>
                                        <div
                                            class="text-2xl font-black text-amber-700 dark:text-amber-400 mt-3 tabular-nums">
                                            Rs. <?php echo number_format($winner["winning_bid"]); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php elseif ($auction["auction_status"] === "ended"): ?>
                                <div
                                    class="m-6 p-5 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-700 dark:text-rose-300 text-sm text-center font-semibold flex items-center justify-center gap-2">
                                    <i class="fa-solid fa-circle-xmark"></i>
                                    This auction ended without any bids.
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Countdown -->
                        <?php if (!$is_ended && $auction["auction_status"] !== "completed"): ?>
                            <div class="px-6 pt-6">
                                <div class="relative overflow-hidden rounded-2xl bg-slate-950 p-5 shadow-inner auction-countdown noise-overlay"
                                    data-end-time="<?php echo date("Y-m-d H:i:s", strtotime($auction["end_time"])); ?>">
                                    <div
                                        class="absolute -right-8 -top-8 w-32 h-32 bg-indigo-600/20 rounded-full blur-3xl pointer-events-none">
                                    </div>
                                    <div class="relative">
                                        <div class="flex items-center justify-between text-xs mb-3 gap-2">
                                            <span class="flex items-center gap-1.5 font-bold text-emerald-400">
                                                <i class="fa-solid fa-clock"></i>
                                                Auction Closes In
                                            </span>
                                            <span
                                                class="text-[11px] bg-white/10 text-gray-200 px-2 py-0.5 rounded font-mono">
                                                <?php echo date("d M Y, h:i A", strtotime($auction["end_time"])); ?>
                                            </span>
                                        </div>
                                        <div class="grid grid-cols-4 gap-2 text-center tabular-nums">
                                            <div class="bg-white/10 backdrop-blur rounded-xl p-2.5 border border-white/5">
                                                <div id="days" class="text-xl font-black text-white">00</div>
                                                <div class="text-[9px] uppercase tracking-wider text-gray-400 mt-0.5">Days
                                                </div>
                                            </div>
                                            <div class="bg-white/10 backdrop-blur rounded-xl p-2.5 border border-white/5">
                                                <div id="hours" class="text-xl font-black text-white">00</div>
                                                <div class="text-[9px] uppercase tracking-wider text-gray-400 mt-0.5">Hours
                                                </div>
                                            </div>
                                            <div class="bg-white/10 backdrop-blur rounded-xl p-2.5 border border-white/5">
                                                <div id="minutes" class="text-xl font-black text-white">00</div>
                                                <div class="text-[9px] uppercase tracking-wider text-gray-400 mt-0.5">Mins
                                                </div>
                                            </div>
                                            <div
                                                class="bg-indigo-500/30 border border-indigo-400/40 backdrop-blur rounded-xl p-2.5 countdown-seconds-pulse">
                                                <div id="seconds" class="text-xl font-black text-white">00</div>
                                                <div class="text-[9px] uppercase tracking-wider text-indigo-200 mt-0.5">Secs
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Bid Form -->
                            <div id="bidding-forms-container" class="p-6">

                                <?php if ($current_user_kyc_status === 'approved' && isset($_SESSION["user_id"])): ?>
                                    <!-- ★ KYC APPROVED — SHOW BID FORM -->
                                    <form action="bid-confirmation.php" method="POST" id="bid-placement-form" class="space-y-5">
                                        <?php nb_csrf_field(); ?>
                                        <input type="hidden" name="auction_id" value="<?php echo $auction_id; ?>">

                                        <div>
                                            <label
                                                class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-2"
                                                for="live-bid-amount">
                                                Place Your Bid
                                            </label>
                                            <div class="relative rounded-2xl shadow-sm">
                                                <div
                                                    class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4">
                                                    <span class="text-gray-500 dark:text-gray-400 font-bold text-sm">Rs.</span>
                                                </div>
                                                <input
                                                    class="block w-full rounded-2xl border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-slate-800 pl-12 pr-14 py-4 text-2xl font-black text-gray-900 dark:text-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/30 tabular-nums transition-all"
                                                    id="live-bid-amount" name="bid_amount" type="number"
                                                    min="<?php echo htmlspecialchars($minimum_bid); ?>" step="1" required
                                                    value="<?php echo htmlspecialchars($minimum_bid); ?>">
                                                <div class="absolute inset-y-0 right-0 flex items-center pr-4">
                                                    <span
                                                        class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">PKR</span>
                                                </div>
                                            </div>
                                            <p
                                                class="text-[11px] text-gray-500 dark:text-gray-400 mt-2 flex items-center gap-1.5">
                                                <i class="fa-solid fa-circle-info text-[10px]"></i>
                                                Minimum bid: Rs. <?php echo number_format($minimum_bid); ?> (increment of Rs.
                                                <?php echo number_format($bid_increment); ?>)
                                            </p>
                                        </div>

                                        <div class="space-y-2">
                                            <span
                                                class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider flex items-center gap-1.5">
                                                <i class="fa-solid fa-bolt text-amber-500 text-[10px]"></i>
                                                Quick Increment
                                            </span>
                                            <div class="grid grid-cols-3 gap-2">
                                                <button type="button" data-increment="500"
                                                    class="quick-increment py-2.5 px-3 text-xs font-bold rounded-xl border-2 border-gray-200 dark:border-gray-700 hover:border-indigo-500 dark:hover:border-indigo-500 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-700 dark:hover:text-indigo-300 text-gray-700 dark:text-gray-300 transition-all duration-200">+Rs.
                                                    500</button>
                                                <button type="button" data-increment="1000"
                                                    class="quick-increment py-2.5 px-3 text-xs font-bold rounded-xl border-2 border-gray-200 dark:border-gray-700 hover:border-indigo-500 dark:hover:border-indigo-500 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-700 dark:hover:text-indigo-300 text-gray-700 dark:text-gray-300 transition-all duration-200">+Rs.
                                                    1,000</button>
                                                <button type="button" data-increment="2500"
                                                    class="quick-increment py-2.5 px-3 text-xs font-bold rounded-xl border-2 border-gray-200 dark:border-gray-700 hover:border-indigo-500 dark:hover:border-indigo-500 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-700 dark:hover:text-indigo-300 text-gray-700 dark:text-gray-300 transition-all duration-200">+Rs.
                                                    2,500</button>
                                            </div>
                                        </div>

                                        <button type="submit"
                                            class="group w-full py-4 px-6 rounded-2xl bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white font-black text-base shadow-xl shadow-indigo-600/30 hover:shadow-indigo-500/50 flex items-center justify-center gap-2 transition-all duration-300 hover:-translate-y-0.5 active:translate-y-0">
                                            <i
                                                class="fa-solid fa-gavel group-hover:rotate-12 transition-transform duration-300"></i>
                                            Place Bid for Rs. <span id="bid-button-amount"
                                                class="tabular-nums"><?php echo number_format($minimum_bid); ?></span>
                                        </button>

                                        <div
                                            class="flex items-start gap-2.5 p-3.5 rounded-xl bg-amber-500/5 border border-amber-500/20">
                                            <i class="fa-solid fa-lightbulb text-amber-500 mt-0.5"></i>
                                            <div class="text-xs">
                                                <div class="font-bold text-gray-800 dark:text-gray-200">Fast Bid Entry</div>
                                                <div class="text-gray-500 dark:text-gray-400 mt-0.5 leading-relaxed">Quick
                                                    increment buttons adjust your amount before submission. Bids are final once
                                                    placed.</div>
                                            </div>
                                        </div>
                                    </form>

                                <?php elseif ($current_user_kyc_status !== 'guest' && $current_user_kyc_status !== 'approved'): ?>
                                    <!-- ★ KYC NOT APPROVED — SHOW KYC REQUIRED BANNER -->
                                    <div class="p-6 rounded-2xl bg-amber-500/10 border border-amber-500/30 text-center">
                                        <div
                                            class="w-14 h-14 rounded-2xl bg-amber-500/20 text-amber-600 dark:text-amber-400 flex items-center justify-center text-2xl mx-auto mb-3">
                                            <i class="fa-solid fa-shield-halved"></i>
                                        </div>
                                        <p class="text-sm font-bold text-gray-900 dark:text-white">KYC Verification Required</p>
                                        <p
                                            class="text-xs text-gray-500 dark:text-gray-400 mt-1.5 max-w-xs mx-auto leading-relaxed">
                                            <?php if ($current_user_kyc_status === 'pending'): ?>
                                                Your KYC is under review. You can bid once verified.
                                            <?php elseif ($current_user_kyc_status === 'rejected'): ?>
                                                Your KYC was rejected. Please resubmit with correct documents.
                                            <?php else: ?>
                                                Please complete identity verification to place bids on this auction.
                                            <?php endif; ?>
                                        </p>
                                        <a href="user/kyc-status.php"
                                            class="inline-flex items-center gap-2 mt-4 px-5 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-sm font-bold transition-all duration-200 hover:shadow-lg hover:shadow-amber-600/30">
                                            <i class="fa-solid fa-id-card"></i>
                                            <?php echo $current_user_kyc_status === 'pending' ? 'View KYC Status' : 'Verify Now'; ?>
                                        </a>
                                    </div>

                                <?php else: ?>
                                    <!-- ★ GUEST — SHOW LOGIN PROMPT -->
                                    <div
                                        class="p-6 rounded-2xl bg-gray-50 dark:bg-slate-800/50 border border-gray-200 dark:border-gray-800 text-center">
                                        <div
                                            class="w-14 h-14 rounded-2xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-2xl mx-auto mb-3">
                                            <i class="fa-solid fa-lock"></i>
                                        </div>
                                        <p class="text-sm font-bold text-gray-900 dark:text-white">Login required to place a bid
                                        </p>
                                        <p
                                            class="text-xs text-gray-500 dark:text-gray-400 mt-1.5 max-w-xs mx-auto leading-relaxed">
                                            Sign in to your NexusBids account to participate in this auction.
                                        </p>
                                        <div class="flex flex-wrap gap-2 justify-center mt-5">
                                            <a href="auth/login.php"
                                                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold transition-all duration-200 hover:shadow-lg hover:shadow-indigo-600/30">
                                                <i class="fa-solid fa-right-to-bracket"></i> Login
                                            </a>
                                            <a href="auth/register.php"
                                                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-700 hover:border-indigo-500 dark:hover:border-indigo-500 text-gray-700 dark:text-gray-200 text-sm font-bold transition-all duration-200">
                                                Register
                                            </a>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Opening bid notice -->
                            <?php if (empty($bid_history_data) && $current_user_kyc_status === 'approved'): ?>
                                <div class="px-6 pb-6">
                                    <div class="flex items-start gap-3 p-4 rounded-xl bg-rose-500/5 border border-rose-500/20">
                                        <i class="fa-solid fa-gavel text-rose-500 mt-0.5"></i>
                                        <div class="text-xs">
                                            <span class="font-bold text-rose-700 dark:text-rose-300">Opening Bid
                                                Available!</span>
                                            <span class="text-gray-600 dark:text-gray-400"> Be the first bidder — minimum bid is
                                                Rs. <?php echo number_format($minimum_bid); ?>.</span>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Auction Info Sidebar Card -->
                <div
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <div class="p-5 border-b border-gray-100 dark:border-gray-800">
                        <h3 class="text-sm font-bold text-gray-900 dark:text-white flex items-center gap-2.5">
                            <span
                                class="w-8 h-8 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs">
                                <i class="fa-solid fa-calendar-days"></i>
                            </span>
                            Auction Information
                        </h3>
                    </div>
                    <div class="p-5 space-y-3.5">
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-2">
                                <i class="fa-solid fa-play-circle text-gray-400 text-[10px]"></i> Start Date
                            </span>
                            <span
                                class="text-xs font-bold text-gray-900 dark:text-white"><?php echo date("d M Y", strtotime($auction["start_time"])); ?></span>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-2">
                                <i class="fa-solid fa-flag-checkered text-gray-400 text-[10px]"></i> End Date
                            </span>
                            <span
                                class="text-xs font-bold text-gray-900 dark:text-white"><?php echo date("d M Y, h:i A", strtotime($auction["end_time"])); ?></span>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-2">
                                <i class="fa-solid fa-layer-group text-gray-400 text-[10px]"></i> Category
                            </span>
                            <span
                                class="text-[11px] font-bold text-indigo-700 dark:text-indigo-300 bg-indigo-50 dark:bg-indigo-950/40 px-2.5 py-1 rounded-md"><?php echo htmlspecialchars($auction["category_name"]); ?></span>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-2">
                                <i class="fa-solid fa-hashtag text-gray-400 text-[10px]"></i> Lot ID
                            </span>
                            <span
                                class="text-xs font-bold text-gray-900 dark:text-white font-mono">#<?php echo str_pad($auction_id, 5, '0', STR_PAD_LEFT); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Trust Card -->
                <div
                    class="relative rounded-3xl bg-slate-950 text-white p-6 overflow-hidden shadow-xl border border-gray-800 noise-overlay">
                    <div
                        class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-indigo-900/40 via-slate-950 to-slate-950">
                    </div>
                    <div
                        class="absolute -right-12 -top-12 w-48 h-48 bg-emerald-600/20 rounded-full blur-3xl pointer-events-none">
                    </div>

                    <div class="relative">
                        <div class="flex items-start gap-3 mb-4">
                            <div
                                class="w-10 h-10 rounded-xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center shrink-0">
                                <i class="fa-solid fa-shield-halved"></i>
                            </div>
                            <div>
                                <h4 class="text-sm font-bold">Buyer Protection</h4>
                                <p class="text-[11px] text-gray-400 mt-1 leading-relaxed">Your bid is processed through
                                    the secure NexusBids flow with KYC-verified bidders only.</p>
                            </div>
                        </div>
                        <div class="pt-4 border-t border-gray-800 flex items-center justify-between text-[11px] gap-3">
                            <span class="text-gray-400">Payment:</span>
                            <span class="font-bold text-gray-200 flex items-center gap-1.5">
                                <i class="fa-solid fa-lock text-emerald-400 text-[10px]"></i>
                                Secure Flow
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-slate-950 text-gray-400 text-xs border-t border-gray-900 mt-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-8 py-16 grid grid-cols-1 md:grid-cols-5 gap-10">
            <div class="md:col-span-2 space-y-5">
                <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight text-white" href="index.php">
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
                    <li><a class="hover:text-white transition-colors duration-200" href="auctions.php">Browse
                            Auctions</a></li>
                    <li><a class="hover:text-white transition-colors duration-200" href="auctions.php">Live Auctions</a>
                    </li>
                    <li><a class="hover:text-white transition-colors duration-200" href="categories.php">Categories</a>
                    </li>
                    <li><a class="hover:text-white transition-colors duration-200" href="index.php#how-it-works">How It
                            Works</a></li>
                </ul>
            </div>
            <div class="space-y-4">
                <h4 class="text-sm font-bold text-white uppercase tracking-wider">Account</h4>
                <ul class="space-y-2.5">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <li><a class="hover:text-white transition-colors duration-200"
                                href="<?php echo ($_SESSION['role'] ?? '') === 'seller' ? 'seller/dashboard.php' : 'user/dashboard.php'; ?>">My
                                Dashboard</a></li>
                        <li><a class="hover:text-white transition-colors duration-200"
                                href="notifications.php">Notifications</a></li>
                        <li><a class="hover:text-white transition-colors duration-200" href="auth/logout.php">Logout</a>
                        </li>
                    <?php else: ?>
                        <li><a class="hover:text-white transition-colors duration-200" href="auth/login.php">Login</a></li>
                        <li><a class="hover:text-white transition-colors duration-200" href="auth/register.php">Register</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
            <div class="space-y-4">
                <h4 class="text-sm font-bold text-white uppercase tracking-wider">Support</h4>
                <ul class="space-y-2.5">
                    <li><a class="hover:text-white transition-colors duration-200" href="about.php">Help Center</a></li>
                    <li><a class="hover:text-white transition-colors duration-200" href="about.php#contact">Contact
                            Us</a></li>
                    <li><a class="hover:text-white transition-colors duration-200" href="about.php#why-nexusbids">Safety
                            Center</a></li>
                    <li><a class="hover:text-white transition-colors duration-200" href="about.php">Terms &amp;
                            Conditions</a></li>
                </ul>
            </div>
        </div>
        <div
            class="border-t border-gray-900 py-6 text-center text-xs text-gray-500 max-w-7xl mx-auto px-4 flex flex-col sm:flex-row justify-between items-center gap-4">
            <p>© <?php echo date("Y"); ?> NexusBids. All rights reserved.</p>
            <p>Made for Pakistan <span class="text-rose-500"><i class="fa-solid fa-heart"></i></span></p>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
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

            const bidInput = document.getElementById('live-bid-amount');
            const bidButtonAmount = document.getElementById('bid-button-amount');

            function syncBidButton() {
                if (!bidInput || !bidButtonAmount) return;
                const value = parseFloat(bidInput.value || 0);
                bidButtonAmount.textContent = Number.isFinite(value)
                    ? value.toLocaleString('en-PK', {maximumFractionDigits: 0})
                    : '0';
            }

            if (bidInput) {
                bidInput.addEventListener('input', syncBidButton);
                syncBidButton();

                document.querySelectorAll('.quick-increment').forEach(function (button) {
                    button.addEventListener('click', function () {
                        const increment = parseFloat(button.dataset.increment || 0);
                        const current = parseFloat(bidInput.value || <?php echo json_encode($minimum_bid); ?>);
                        bidInput.value = Math.round(current + increment);
                        syncBidButton();
                    });
                });
            }
        });

        (function () {
            var auctionId = <?php echo intval($auction_id); ?>;
            var countdownEl = document.querySelector('.auction-countdown');
            if (!countdownEl) return;

            var rawTime = countdownEl.getAttribute('data-end-time').replace(/-/g, '/');
            var endTime = new Date(rawTime).getTime();
            if (isNaN(endTime)) return;

            var timerInterval = setInterval(function () {
                var now = new Date().getTime();
                var distance = endTime - now;
                if (distance <= 0) {
                    clearInterval(timerInterval);
                    announceWinnerAutomatically();
                    return;
                }
                var days = Math.floor(distance / (1000 * 60 * 60 * 24));
                var hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                var minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                var seconds = Math.floor((distance % (1000 * 60)) / 1000);
                var dEl = document.getElementById('days');
                var hEl = document.getElementById('hours');
                var mEl = document.getElementById('minutes');
                var sEl = document.getElementById('seconds');
                if (dEl) dEl.textContent = String(days).padStart(2, '0');
                if (hEl) hEl.textContent = String(hours).padStart(2, '0');
                if (mEl) mEl.textContent = String(minutes).padStart(2, '0');
                if (sEl) sEl.textContent = String(seconds).padStart(2, '0');
            }, 1000);

            function announceWinnerAutomatically() {
                countdownEl.innerHTML = "<div class='text-center text-amber-300 font-bold py-4'><i class='fa-solid fa-spinner fa-spin mr-2'></i> Declaring Winner...</div>";
                fetch("auction-details.php?id=" + auctionId + "&ajax_finalize=1")
                    .then(function (res) {return res.json();})
                    .then(function () {setTimeout(function () {window.location.reload();}, 1200);})
                    .catch(function () {window.location.reload();});
            }
        })();
    </script>
    <?php require_once __DIR__ . '/includes/chat-widget.php'; ?>

</body>

</html>