<?php


error_reporting(E_ALL);
ini_set('display_errors', 1);   // Localhost: 1 | Production: 0
ini_set('log_errors', 1);

/* ---------- 1. SAFE SESSION ---------- */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------- 2. AUTH GUARD (accept user/buyer) ---------- */
$user_id = intval($_SESSION["user_id"] ?? 0);
$user_role = strtolower(trim($_SESSION["role"] ?? ""));

if ($user_id <= 0 || !in_array($user_role, ['user', 'buyer'], true)) {
    header("Location: ../auth/login.php");
    exit();
}

/* ---------- 3. SAFE DB CONNECTION ---------- */
$config_paths = [
    __DIR__ . "/../config/database.php",
    __DIR__ . "/../includes/db.php",
    __DIR__ . "/../includes/config.php",
    __DIR__ . "/../includes/connection.php",
];
$config_loaded = false;
foreach ($config_paths as $cfg) {
    if (file_exists($cfg)) {
        require_once $cfg;
        $config_loaded = true;
        if (isset($conn) && $conn instanceof mysqli)
            break;
    }
}
if (!$config_loaded || !isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection failed. Check config/database.php");
}
$conn->set_charset("utf8mb4");

/* ---------- 4. SAFE OPTIONAL INCLUDES ---------- */
$process_file = __DIR__ . "/../includes/process-auctions.php";
if (file_exists($process_file)) {
    require_once $process_file;
    if (function_exists('processEndedAuctions')) {
        try {
            processEndedAuctions($conn);
        } catch (Throwable $e) {
            error_log("processEndedAuctions error: " . $e->getMessage());
        }
    }
}

$notif_file = __DIR__ . "/../includes/notifications.php";
if (file_exists($notif_file)) {
    require_once $notif_file;
}

/* ---------- 5. HELPERS ---------- */
if (!function_exists('compact_rs')) {
    function compact_rs($amount)
    {
        $amount = floatval($amount);
        if ($amount >= 10000000)
            return round($amount / 10000000, 1) . "Cr";
        if ($amount >= 100000)
            return round($amount / 100000, 1) . "L";
        if ($amount >= 1000)
            return round($amount / 1000) . "K";
        return number_format($amount);
    }
}

if (!function_exists('table_exists')) {
    function table_exists($conn, $table)
    {
        $t = $conn->real_escape_string($table);
        $r = $conn->query("SHOW TABLES LIKE '$t'");
        return $r && $r->num_rows > 0;
    }
}

if (!function_exists('column_exists')) {
    function column_exists($conn, $table, $column)
    {
        $t = $conn->real_escape_string($table);
        $c = $conn->real_escape_string($column);
        $r = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
        return $r && $r->num_rows > 0;
    }
}

if (!function_exists('safe_date')) {
    function safe_date($date, $format = 'd M Y')
    {
        if (empty($date) || $date === '0000-00-00 00:00:00' || $date === '0000-00-00')
            return '—';
        $ts = strtotime($date);
        return $ts ? date($format, $ts) : '—';
    }
}

if (!function_exists('safe_initials')) {
    function safe_initials($name, $fallback = 'U')
    {
        $name = trim((string) $name);
        if ($name === '')
            return $fallback;
        $clean = preg_replace('/\s+/', '', $name);
        $init = strtoupper(substr($clean, 0, 2));
        return $init !== '' ? $init : $fallback;
    }
}

if (!function_exists('won_image_url')) {
    function won_image_url($img)
    {
        if (empty($img))
            return '';
        if (preg_match('#^https?://#i', $img))
            return $img;

        $base = basename($img);
        $paths = [
            "../uploads/auctions/" . $base,
            "../uploads/" . $base,
            "../assets/uploads/auctions/" . $base,
            "../uploads/auction/" . $base,
        ];
        foreach ($paths as $p) {
            if (file_exists(__DIR__ . '/' . $p))
                return $p;
        }
        return "../uploads/auctions/" . rawurlencode($base);
    }
}

/* ---------- 6. USER INFO ---------- */
$full_name = "User";
$initials = "US";

$user_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ? LIMIT 1");
if ($user_stmt) {
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $uinfo = $user_stmt->get_result()->fetch_assoc();
    if ($uinfo && !empty($uinfo['full_name'])) {
        $full_name = $uinfo['full_name'];
        $initials = safe_initials($full_name, 'US');
    }
    $user_stmt->close();
}

/* ---------- 7. UNREAD NOTIFICATIONS ---------- */
$unread_notifications = 0;
if (function_exists('nb_unread_count')) {
    try {
        $unread_notifications = intval(nb_unread_count($conn, $user_id));
    } catch (Throwable $e) {
        $unread_notifications = 0;
    }
}

/* ---------- 8. DETECT OPTIONAL TABLES/COLUMNS ---------- */
$has_winners = table_exists($conn, 'winners');
$has_payments = table_exists($conn, 'payments');
$has_reviews = table_exists($conn, 'reviews');

$auction_has_image = table_exists($conn, 'auctions') && column_exists($conn, 'auctions', 'image');

$payment_has_status = $has_payments && column_exists($conn, 'payments', 'payment_status');
$payment_has_method = $has_payments && column_exists($conn, 'payments', 'payment_method');
$payment_has_txn = $has_payments && column_exists($conn, 'payments', 'transaction_id');
$payment_has_id = $has_payments && column_exists($conn, 'payments', 'payment_id');
$payment_has_auction = $has_payments && column_exists($conn, 'payments', 'auction_id');
$payment_has_user = $has_payments && column_exists($conn, 'payments', 'user_id');
$payment_has_order = $has_payments && column_exists($conn, 'payments', 'order_id');

$review_has_id = $has_reviews && column_exists($conn, 'reviews', 'review_id');
$review_has_auction = $has_reviews && column_exists($conn, 'reviews', 'auction_id');
$review_has_reviewer = $has_reviews && column_exists($conn, 'reviews', 'reviewer_id');

/* ---------- 9. FETCH WINS ---------- */
$wins_data = [];
$total_wins = 0;
$total_spent = 0;
$paid_count = 0;
$pending_count = 0;
$reviewed_count = 0;

if ($has_winners) {
    $winner_has_user = column_exists($conn, 'winners', 'user_id');
    $winner_has_auction = column_exists($conn, 'winners', 'auction_id');
    $winner_has_bid = column_exists($conn, 'winners', 'winning_bid');
    $winner_has_created = column_exists($conn, 'winners', 'created_at');

    if ($winner_has_user && $winner_has_auction) {
        $select = [
            "w.winner_id",
            "a.auction_id",
            "a.title",
            "a.seller_id",
            "u.full_name AS seller_name",
        ];

        if ($winner_has_bid)
            $select[] = "w.winning_bid";
        if ($winner_has_created)
            $select[] = "w.created_at";
        if ($auction_has_image)
            $select[] = "a.image";

        $payment_join = "";
        if ($has_payments && $payment_has_auction && $payment_has_user) {
            $p_cols = ["p_inner.payment_id"];
            if ($payment_has_status) $p_cols[] = "p_inner.payment_status";
            if ($payment_has_method) $p_cols[] = "p_inner.payment_method";
            if ($payment_has_txn)    $p_cols[] = "p_inner.transaction_id";

            $select[] = "p.payment_id";
            if ($payment_has_status) $select[] = "p.payment_status";
            if ($payment_has_method) $select[] = "p.payment_method";
            if ($payment_has_txn)    $select[] = "p.transaction_id";

            $payment_join = "
                LEFT JOIN (
                    SELECT p1.* FROM payments p1
                    INNER JOIN (
                        SELECT auction_id, user_id, MAX(payment_id) AS max_id
                        FROM payments
                        GROUP BY auction_id, user_id
                    ) p2 ON p1.payment_id = p2.max_id
                ) p ON p.auction_id = a.auction_id AND p.user_id = w.user_id
            ";
        }

        $review_join = "";
        if ($has_reviews && $review_has_auction && $review_has_reviewer) {
            $select[] = "r.review_id";
            $review_join = "LEFT JOIN reviews r ON r.auction_id = a.auction_id AND r.reviewer_id = w.user_id";
        }

        $sql = "SELECT " . implode(", ", $select) . "
                FROM winners w
                INNER JOIN auctions a ON w.auction_id = a.auction_id
                INNER JOIN users u ON a.seller_id = u.user_id
                $payment_join
                $review_join
                WHERE w.user_id = ?
                ORDER BY " . ($winner_has_created ? "w.created_at" : "w.winner_id") . " DESC";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $ps = strtolower(trim((string) ($row["payment_status"] ?? "")));
                    $is_paid = in_array($ps, ['completed', 'paid', 'success', 'successful'], true);

                    $row['_is_paid'] = $is_paid;
                    $row['_payment_key'] = $is_paid ? 'paid' : 'pending';
                    $row['_review_key'] = !empty($row['review_id']) ? 'reviewed' : 'pending';

                    $wins_data[] = $row;
                    $total_spent += floatval($row["winning_bid"] ?? 0);

                    if ($is_paid)
                        $paid_count++;
                    else
                        $pending_count++;

                    if (!empty($row["review_id"]))
                        $reviewed_count++;
                }
            } else {
                error_log("Won auctions query failed: " . $stmt->error);
            }
            $stmt->close();
        } else {
            error_log("Won auctions prepare failed: " . $conn->error);
        }
    }
}

$total_wins = count($wins_data);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Won Auctions | NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <meta name="description" content="Auctions you've won on NexusBids — payment status, seller info, and reviews.">

    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
                    },
                    animation: {
                        'fade-up': 'fadeUp 0.6s ease-out forwards',
                        'pulse-soft': 'pulseSoft 2s ease-in-out infinite',
                    },
                    keyframes: {
                        fadeUp: { '0%': { opacity: '0', transform: 'translateY(16px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                        pulseSoft: { '0%, 100%': { opacity: '1' }, '50%': { opacity: '0.5' } },
                    }
                }
            }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <style>
        html { scroll-behavior: smooth; }
        body { transition: background-color .3s ease, color .3s ease; }
        .glass { backdrop-filter: blur(16px) saturate(180%); -webkit-backdrop-filter: blur(16px) saturate(180%); }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .dark ::-webkit-scrollbar-thumb { background: #334155; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .dark ::-webkit-scrollbar-thumb:hover { background: #475569; }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; }
        }
        .win-row { transition: background-color .2s ease; }
        .win-row:hover { background-color: rgb(238 242 255 / 0.5); }
        .dark .win-row:hover { background-color: rgb(30 41 59 / 0.5); }
        .custom-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L2 4h8z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            appearance: none;
        }
    </style>
</head>

<body class="bg-gray-50 text-gray-900 dark:bg-slate-950 dark:text-gray-100 font-sans antialiased">

    <!-- Top Utility Bar -->
    <div class="bg-gray-950 text-gray-400 text-xs py-2.5 px-4 sm:px-8 border-b border-gray-800/50 relative z-40">
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-2">
            <div class="flex items-center space-x-6">
                <span class="flex items-center gap-2"><i class="fa-solid fa-location-dot text-indigo-400"></i> Pakistan</span>
                <span class="hidden sm:flex items-center gap-2"><i class="fa-solid fa-phone text-indigo-400"></i> +92 300 1234567</span>
            </div>
            <div class="flex items-center space-x-6">
                <a href="../about.php" class="hover:text-white transition-colors duration-200"><i class="fa-regular fa-circle-question text-indigo-400"></i> Help Center</a>
                <a href="../about.php#why-nexusbids" class="hover:text-white transition-colors duration-200"><i class="fa-solid fa-shield-halved text-indigo-400"></i> Secure Bidding</a>
            </div>
        </div>
    </div>

    <!-- Main Navbar -->
    <header class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
            <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight shrink-0 group" href="../index.php">
                <span class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg group-hover:scale-105 transition-all">
                    <i class="fa-solid fa-gavel text-lg"></i>
                </span>
                Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
            </a>
            <nav class="hidden lg:flex items-center gap-7 text-sm font-medium">
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition" href="../index.php">Home</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition" href="../auctions.php">Auctions</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition" href="../categories.php">Categories</a>
                <a class="relative text-indigo-600 dark:text-indigo-400 font-semibold after:absolute after:-bottom-1 after:left-0 after:w-full after:h-0.5 after:bg-indigo-600 after:rounded-full" href="dashboard.php">Dashboard</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition" href="../about.php">About</a>
            </nav>
            <div class="flex items-center gap-2 sm:gap-3">
                <button aria-label="Toggle Theme" id="themeToggle" class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition">
                    <i class="fa-solid fa-moon dark:hidden"></i><i class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                </button>
                <a href="../notifications.php" class="relative w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($unread_notifications > 0): ?>
                        <span class="absolute top-1.5 right-1.5 w-2.5 h-2.5 rounded-full bg-rose-500 border-2 border-white dark:border-gray-800 animate-pulse-soft"></span>
                    <?php endif; ?>
                </a>
                <div class="flex items-center gap-3 pl-3 border-l border-gray-200 dark:border-gray-800">
                    <div class="relative">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center shadow-lg ring-2 ring-white dark:ring-slate-900">
                            <?php echo htmlspecialchars($initials); ?>
                        </div>
                        <span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-emerald-500 border-2 border-white dark:border-slate-900"></span>
                    </div>
                    <div class="hidden sm:block text-left">
                        <div class="text-sm font-bold leading-tight truncate max-w-32">
                            <?php echo htmlspecialchars($full_name); ?>
                        </div>
                        <div class="text-[11px] text-emerald-600 dark:text-emerald-400 font-medium flex items-center gap-1">
                            <i class="fa-solid fa-circle-check text-[8px]"></i> Verified Bidder
                        </div>
                    </div>
                </div>
                <a class="hidden sm:inline-flex px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition" href="../auth/logout.php">
                    <i class="fa-solid fa-right-from-bracket mr-1.5"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-[1600px] mx-auto w-full px-4 sm:px-8 py-8 space-y-8">

        <!-- Page Header -->
        <section class="animate-fade-up">
            <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                <div>
                    <nav class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mb-3">
                        <a href="dashboard.php" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">Dashboard</a>
                        <i class="fa-solid fa-chevron-right text-[8px]"></i>
                        <span class="text-gray-900 dark:text-white font-semibold">Won Auctions</span>
                    </nav>
                    <h1 class="text-3xl sm:text-4xl font-black tracking-tight text-gray-900 dark:text-white flex items-center gap-3">
                        <span class="w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-500 flex items-center justify-center text-white shadow-lg shadow-amber-500/30">
                            <i class="fa-solid fa-trophy text-lg"></i>
                        </span>
                        Won Auctions
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Auctions you've won and their payment status.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="../auctions.php" class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold transition-all duration-200 hover:shadow-lg hover:shadow-indigo-600/30 flex items-center gap-2">
                        <i class="fa-solid fa-magnifying-glass"></i> Browse Auctions
                    </a>
                    <a href="dashboard.php" class="px-5 py-2.5 rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 hover:border-indigo-500 dark:hover:border-indigo-500 text-gray-700 dark:text-gray-200 text-sm font-semibold transition-all duration-200 flex items-center gap-2">
                        <i class="fa-solid fa-chart-line"></i> Dashboard
                    </a>
                </div>
            </div>
        </section>

        <!-- Stats -->
        <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 animate-fade-up" style="animation-delay: 0.05s">
            <div class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-amber-500 transition-all duration-300">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Wins</span>
                    <span class="w-9 h-9 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-xs group-hover:scale-110 transition-transform">
                        <i class="fa-solid fa-trophy"></i>
                    </span>
                </div>
                <div class="text-2xl font-black tabular-nums"><?php echo (int) $total_wins; ?></div>
                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">Lifetime auction wins</p>
            </div>

            <div class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-indigo-500 transition-all duration-300">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Spent</span>
                    <span class="w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs group-hover:scale-110 transition-transform">
                        <i class="fa-solid fa-coins"></i>
                    </span>
                </div>
                <div class="text-2xl font-black tabular-nums">Rs. <?php echo compact_rs($total_spent); ?></div>
                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">Across all winnings</p>
            </div>

            <div class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-emerald-500 transition-all duration-300">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Paid</span>
                    <span class="w-9 h-9 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xs group-hover:scale-110 transition-transform">
                        <i class="fa-solid fa-circle-check"></i>
                    </span>
                </div>
                <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums"><?php echo (int) $paid_count; ?></div>
                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">Completed payments</p>
            </div>

            <div class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-rose-500 transition-all duration-300">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Pending</span>
                    <span class="w-9 h-9 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center text-xs group-hover:scale-110 transition-transform">
                        <i class="fa-solid fa-clock"></i>
                    </span>
                </div>
                <div class="text-2xl font-black <?php echo $pending_count > 0 ? 'text-rose-600 dark:text-rose-400' : ''; ?> tabular-nums">
                    <?php echo (int) $pending_count; ?>
                </div>
                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">
                    <?php echo $pending_count > 0 ? 'Needs payment' : 'All settled'; ?>
                </p>
            </div>
        </section>

        <!-- Alert Banner -->
        <?php if ($pending_count > 0): ?>
            <div class="flex items-start gap-4 p-4 sm:p-5 rounded-2xl bg-gradient-to-r from-amber-500/10 to-rose-500/10 border border-amber-500/30 animate-fade-up" style="animation-delay: 0.08s">
                <div class="w-11 h-11 rounded-xl bg-amber-500/20 text-amber-600 dark:text-amber-400 flex items-center justify-center text-lg shrink-0">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="font-bold text-gray-900 dark:text-white text-sm">
                        You have <?php echo (int) $pending_count; ?> pending payment<?php echo $pending_count === 1 ? '' : 's'; ?>
                    </h3>
                    <p class="text-xs text-gray-600 dark:text-gray-300 mt-1 leading-relaxed">Complete payment to secure your winning auctions and coordinate delivery with sellers.</p>
                </div>
                <a href="#pending-payments" class="hidden sm:inline-flex px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold whitespace-nowrap transition-all duration-200">
                    Pay Now
                </a>
            </div>
        <?php endif; ?>

        <!-- Wins Table -->
        <section class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden animate-fade-up" style="animation-delay: 0.1s">

            <!-- Toolbar -->
            <div class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row justify-between items-center gap-4">
                <div class="relative w-full sm:w-72 group">
                    <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 group-focus-within:text-indigo-500 transition-colors text-sm"></i>
                    <input id="winSearch"
                        class="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all placeholder:text-gray-400"
                        placeholder="Search wins or seller..." type="text" autocomplete="off">
                </div>
                <div class="flex items-center gap-2">
                    <select id="paymentFilter"
                        class="custom-select py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all cursor-pointer">
                        <option value="all">All Payments</option>
                        <option value="paid">Paid</option>
                        <option value="pending">Pending</option>
                    </select>
                    <select id="reviewFilter"
                        class="custom-select py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all cursor-pointer">
                        <option value="all">All Reviews</option>
                        <option value="reviewed">Reviewed</option>
                        <option value="pending">Not Reviewed</option>
                    </select>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm" id="winsTable">
                    <thead class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <th class="px-6 py-4 text-left text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Item</th>
                            <th class="px-6 py-4 text-left text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Seller</th>
                            <th class="px-6 py-4 text-left text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Winning Bid</th>
                            <th class="px-6 py-4 text-left text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Won On</th>
                            <th class="px-6 py-4 text-left text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Payment</th>
                            <th class="px-6 py-4 text-right text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <?php if (!empty($wins_data)): ?>
                            <?php
                            $first_pending = true;
                            foreach ($wins_data as $win):
                                $is_paid = !empty($win['_is_paid']);
                                $payment_key = $win['_payment_key'];
                                $review_key = $win['_review_key'];
                                $img_url = won_image_url($win['image'] ?? '');
                                $row_id = ($payment_key === 'pending' && $first_pending) ? 'pending-payments' : '';
                                if ($payment_key === 'pending')
                                    $first_pending = false;
                                ?>
                                <tr class="win-row"
                                    data-title="<?php echo htmlspecialchars(strtolower($win['title'] ?? '')); ?>"
                                    data-seller="<?php echo htmlspecialchars(strtolower($win['seller_name'] ?? '')); ?>"
                                    data-payment="<?php echo $payment_key; ?>" data-review="<?php echo $review_key; ?>" <?php echo $row_id ? 'id="' . $row_id . '"' : ''; ?>>
                                    <!-- Item -->
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <?php if ($img_url): ?>
                                                <img src="<?php echo htmlspecialchars($img_url); ?>"
                                                    alt="<?php echo htmlspecialchars($win['title'] ?? ''); ?>"
                                                    class="w-12 h-12 rounded-xl object-cover border border-gray-200 dark:border-gray-800 shrink-0"
                                                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-500/10 to-orange-500/10 text-amber-600 dark:text-amber-400 items-center justify-center text-sm shrink-0" style="display:none;">
                                                    <i class="fa-solid fa-trophy"></i>
                                                </div>
                                            <?php else: ?>
                                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-500/10 to-orange-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-sm shrink-0">
                                                    <i class="fa-solid fa-trophy"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div class="min-w-0">
                                                <a href="../auction-details.php?id=<?php echo (int) ($win['auction_id'] ?? 0); ?>"
                                                    class="font-semibold text-gray-900 dark:text-white hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors line-clamp-1">
                                                    <?php echo htmlspecialchars($win['title'] ?? 'Untitled'); ?>
                                                </a>
                                                <span class="text-[11px] text-gray-400 dark:text-gray-500 font-mono">#<?php echo (int) ($win['auction_id'] ?? 0); ?></span>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Seller -->
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-2">
                                            <div class="w-8 h-8 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-[10px] font-bold shrink-0">
                                                <?php echo htmlspecialchars(safe_initials($win['seller_name'] ?? 'Seller', 'SL')); ?>
                                            </div>
                                            <span class="text-gray-700 dark:text-gray-200 font-medium"><?php echo htmlspecialchars($win['seller_name'] ?? '—'); ?></span>
                                        </div>
                                    </td>

                                    <!-- Winning Bid -->
                                    <td class="px-6 py-4">
                                        <div class="font-black tabular-nums">Rs. <?php echo number_format((float) ($win['winning_bid'] ?? 0)); ?></div>
                                    </td>

                                    <!-- Won On -->
                                    <td class="px-6 py-4">
                                        <div class="text-xs text-gray-600 dark:text-gray-300 font-medium">
                                            <?php echo safe_date($win['created_at'] ?? '', 'd M Y'); ?>
                                        </div>
                                        <div class="text-[11px] text-gray-400 dark:text-gray-500">
                                            <?php echo safe_date($win['created_at'] ?? '', 'h:i A'); ?>
                                        </div>
                                    </td>

                                    <!-- Payment Status -->
                                    <td class="px-6 py-4">
                                        <?php if ($is_paid): ?>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 text-[11px] font-bold border border-emerald-500/20">
                                                <i class="fa-solid fa-circle-check text-[9px]"></i> Paid
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 text-[11px] font-bold border border-rose-500/20">
                                                <span class="w-1.5 h-1.5 rounded-full bg-rose-500 animate-pulse"></span> Pending
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Actions -->
                                    <td class="px-6 py-4">
                                        <div class="flex items-center justify-end gap-2">
                                            <?php if (!$is_paid): ?>
                                                <a href="../checkout.php?auction_id=<?php echo (int) ($win['auction_id'] ?? 0); ?>"
                                                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold whitespace-nowrap">
                                                    <i class="fa-solid fa-credit-card text-[10px]"></i> Pay Now
                                                </a>
                                            <?php endif; ?>

                                            <?php if (empty($win['review_id'])): ?>
                                                <a href="add-review.php?auction_id=<?php echo (int) ($win['auction_id'] ?? 0); ?>"
                                                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-gray-100 dark:bg-slate-800 hover:bg-indigo-600 hover:text-white text-gray-700 dark:text-gray-200 text-xs font-semibold whitespace-nowrap"
                                                    title="Leave a review">
                                                    <i class="fa-regular fa-star text-[10px]"></i> Review
                                                </a>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 text-xs font-bold border border-amber-500/20" title="Review submitted">
                                                    <i class="fa-solid fa-star text-[10px]"></i> Reviewed
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="px-6 py-20 text-center">
                                    <div class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-amber-500/10 to-orange-500/10 text-amber-500 flex items-center justify-center text-3xl mb-5">
                                        <i class="fa-solid fa-trophy"></i>
                                    </div>
                                    <h3 class="text-xl font-black text-gray-900 dark:text-white">No wins yet</h3>
                                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2 max-w-md mx-auto">You haven't won any auctions yet. Place winning bids to see your trophy case grow.</p>
                                    <a href="../auctions.php" class="inline-flex mt-6 px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold items-center gap-2">
                                        <i class="fa-solid fa-magnifying-glass"></i> Browse Auctions
                                    </a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- No Filter Results -->
            <div id="noFilterResults" class="hidden p-12 text-center border-t border-gray-100 dark:border-gray-800">
                <div class="w-16 h-16 mx-auto rounded-2xl bg-gray-100 dark:bg-slate-800 text-gray-400 flex items-center justify-center text-2xl mb-4">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </div>
                <h3 class="font-bold text-gray-900 dark:text-white">No matching wins</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Try adjusting your search or filters.</p>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="bg-slate-950 text-gray-400 text-xs border-t border-gray-900 mt-8">
        <div class="max-w-[1600px] mx-auto px-4 sm:px-8 py-16 grid grid-cols-1 md:grid-cols-5 gap-10">
            <div class="md:col-span-2 space-y-5">
                <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight text-white" href="../index.php">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30">
                        <i class="fa-solid fa-gavel text-lg"></i>
                    </div>
                    Nexus<span class="text-indigo-400">Bids</span>
                </a>
                <p class="text-gray-400 text-xs leading-relaxed max-w-sm">Pakistan's modern online auction marketplace. Discover, bid and win products you love.</p>
                <div class="flex items-center space-x-3 pt-2">
                    <a href="#" class="w-10 h-10 rounded-xl bg-slate-900 border border-gray-800 flex items-center justify-center text-gray-300 hover:text-white hover:border-indigo-500/50"><i class="fa-brands fa-facebook-f"></i></a>
                    <a href="#" class="w-10 h-10 rounded-xl bg-slate-900 border border-gray-800 flex items-center justify-center text-gray-300 hover:text-white hover:border-indigo-500/50"><i class="fa-brands fa-instagram"></i></a>
                    <a href="#" class="w-10 h-10 rounded-xl bg-slate-900 border border-gray-800 flex items-center justify-center text-gray-300 hover:text-white hover:border-indigo-500/50"><i class="fa-brands fa-x-twitter"></i></a>
                    <a href="#" class="w-10 h-10 rounded-xl bg-slate-900 border border-gray-800 flex items-center justify-center text-gray-300 hover:text-white hover:border-indigo-500/50"><i class="fa-brands fa-linkedin-in"></i></a>
                </div>
            </div>
            <div class="space-y-4">
                <h4 class="text-sm font-bold text-white uppercase tracking-wider">Marketplace</h4>
                <ul class="space-y-2.5">
                    <li><a class="hover:text-white" href="../auctions.php">Browse Auctions</a></li>
                    <li><a class="hover:text-white" href="../auctions.php">Live Auctions</a></li>
                    <li><a class="hover:text-white" href="../categories.php">Categories</a></li>
                    <li><a class="hover:text-white" href="../index.php#how-it-works">How It Works</a></li>
                </ul>
            </div>
            <div class="space-y-4">
                <h4 class="text-sm font-bold text-white uppercase tracking-wider">Account</h4>
                <ul class="space-y-2.5">
                    <li><a class="hover:text-white" href="dashboard.php">My Dashboard</a></li>
                    <li><a class="hover:text-white" href="my-bids.php">My Bids</a></li>
                    <li><a class="hover:text-white" href="won-auctions.php">Won Auctions</a></li>
                    <li><a class="hover:text-white" href="watchlist.php">Watchlist</a></li>
                    <li><a class="hover:text-white" href="../auth/logout.php">Logout</a></li>
                </ul>
            </div>
            <div class="space-y-4">
                <h4 class="text-sm font-bold text-white uppercase tracking-wider">Support</h4>
                <ul class="space-y-2.5">
                    <li><a class="hover:text-white" href="../about.php">Help Center</a></li>
                    <li><a class="hover:text-white" href="../about.php#contact">Contact Us</a></li>
                    <li><a class="hover:text-white" href="../about.php#why-nexusbids">Safety Center</a></li>
                    <li><a class="hover:text-white" href="../about.php">Terms &amp; Conditions</a></li>
                    <li><a class="hover:text-white" href="../about.php">Privacy Policy</a></li>
                </ul>
            </div>
        </div>
        <div class="border-t border-gray-900 py-6 text-center text-xs text-gray-500 max-w-[1600px] mx-auto px-4 flex flex-col sm:flex-row justify-between items-center gap-4">
            <p>© <?php echo date("Y"); ?> NexusBids. All rights reserved.</p>
            <p>Made for Pakistan <span class="text-rose-500"><i class="fa-solid fa-heart"></i></span></p>
        </div>
    </footer>

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

            const search = document.getElementById('winSearch');
            const paymentFilter = document.getElementById('paymentFilter');
            const reviewFilter = document.getElementById('reviewFilter');
            const noResults = document.getElementById('noFilterResults');
            const rows = Array.from(document.querySelectorAll('.win-row'));

            function applyFilters() {
                const q = (search?.value || '').trim().toLowerCase();
                const payment = paymentFilter?.value || 'all';
                const review = reviewFilter?.value || 'all';

                let shown = 0;
                rows.forEach(row => {
                    const title = row.dataset.title || '';
                    const seller = row.dataset.seller || '';
                    const rowPayment = row.dataset.payment || '';
                    const rowReview = row.dataset.review || '';

                    const matchesSearch = !q || title.includes(q) || seller.includes(q);
                    const matchesPayment = payment === 'all' || rowPayment === payment;
                    const matchesReview = review === 'all' || rowReview === review;

                    const show = matchesSearch && matchesPayment && matchesReview;
                    row.style.display = show ? '' : 'none';
                    if (show) shown++;
                });

                if (noResults) {
                    noResults.classList.toggle('hidden', !(shown === 0 && rows.length > 0));
                }
            }

            search?.addEventListener('input', applyFilters);
            paymentFilter?.addEventListener('change', applyFilters);
            reviewFilter?.addEventListener('change', applyFilters);
        })();
    </script>
    <?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>