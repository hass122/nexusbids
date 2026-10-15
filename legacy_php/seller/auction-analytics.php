<?php

session_start();

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "seller") {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/notifications.php";

date_default_timezone_set('Asia/Karachi');

$seller_id = intval($_SESSION["user_id"]);
$auction_id = intval($_GET["id"] ?? 0);

if ($auction_id <= 0) {
    header("Location: my-auctions.php");
    exit();
}

/* Seller info */
$s = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ?");
$s->bind_param("i", $seller_id);
$s->execute();
$seller_info = $s->get_result()->fetch_assoc();
$s->close();
$full_name = $seller_info["full_name"] ?? "Seller";
$avatar = $seller_info["avatar"] ?? "";
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));
$unread_notifications = nb_unread_count($conn, $seller_id);

/* Sidebar */
$sidebar_total = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id")->fetch_assoc()["t"] ?? 0;
$sidebar_active = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status='active'")->fetch_assoc()["t"] ?? 0;
$sidebar_pending = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status='pending'")->fetch_assoc()["t"] ?? 0;
$sidebar_drafts = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status='draft'")->fetch_assoc()["t"] ?? 0;

/* Fetch auction */
$a = $conn->prepare("SELECT a.*, c.category_name FROM auctions a
                     INNER JOIN categories c ON a.category_id=c.category_id
                     WHERE a.auction_id = ? AND a.seller_id = ? LIMIT 1");
$a->bind_param("ii", $auction_id, $seller_id);
$a->execute();
$auction = $a->get_result()->fetch_assoc();
$a->close();

if (!$auction) {
    $_SESSION["error"] = "Auction not found.";
    header("Location: my-auctions.php");
    exit();
}

/* Analytics */
$bidStats = $conn->query("
    SELECT COUNT(*) AS total_bids,
           COUNT(DISTINCT bidder_id) AS unique_bidders,
           COALESCE(MAX(bid_amount),0) AS highest_bid,
           COALESCE(MIN(bid_amount),0) AS lowest_bid,
           MAX(bid_time) AS last_bid_time
    FROM bids WHERE auction_id = $auction_id
")->fetch_assoc();

$total_bids = intval($bidStats['total_bids'] ?? 0);
$unique_bidders = intval($bidStats['unique_bidders'] ?? 0);
$highest_bid = floatval($bidStats['highest_bid'] ?? 0);
$lowest_bid = floatval($bidStats['lowest_bid'] ?? 0);
$last_bid_time = $bidStats['last_bid_time'] ?? null;

$views = intval($auction['views_count'] ?? 0);
$watchers = intval($auction['watchlist_count'] ?? 0);
$start_price = floatval($auction['starting_price']);
$current_bid = floatval($auction['current_bid']) > 0 ? floatval($auction['current_bid']) : $start_price;

$conversion = $views > 0 ? round(($total_bids / $views) * 100, 1) : 0;
$price_growth = $start_price > 0 ? round((($current_bid - $start_price) / $start_price) * 100, 1) : 0;

/* Views chart — last 7 days */
$viewsChart = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $row = $conn->query("SELECT COUNT(*) AS t FROM auction_views WHERE auction_id = $auction_id AND DATE(viewed_at) = '$day'")->fetch_assoc();
    $viewsChart[] = ['day' => date('D', strtotime($day)), 'count' => intval($row['t'] ?? 0)];
}
$maxViews = max(1, max(array_column($viewsChart, 'count')));

/* Bids chart — last 7 days */
$bidsChart = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $row = $conn->query("SELECT COUNT(*) AS t FROM bids WHERE auction_id = $auction_id AND DATE(bid_time) = '$day'")->fetch_assoc();
    $bidsChart[] = ['day' => date('D', strtotime($day)), 'count' => intval($row['t'] ?? 0)];
}
$maxBids = max(1, max(array_column($bidsChart, 'count')));

/* Recent bids — anonymous */
$recentBids = $conn->query("
    SELECT bid_amount, bid_time, bidder_id
    FROM bids WHERE auction_id = $auction_id
    ORDER BY bid_time DESC LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

function compact_rs($a)
{
    $a = floatval($a);
    if ($a >= 10000000)
        return round($a / 10000000, 1) . "Cr";
    if ($a >= 100000)
        return round($a / 100000, 1) . "L";
    if ($a >= 1000)
        return round($a / 1000) . "K";
    return number_format($a);
}
function time_ago_short($dt)
{
    if (empty($dt))
        return '';
    $d = time() - strtotime($dt);
    if ($d < 60)
        return "just now";
    if ($d < 3600)
        return intdiv($d, 60) . "m ago";
    if ($d < 86400)
        return intdiv($d, 3600) . "h ago";
    if ($d < 604800)
        return intdiv($d, 86400) . "d ago";
    return date("d M Y", strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics · <?= htmlspecialchars($auction['title']) ?> · NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = {darkMode: 'class', theme: {extend: {fontFamily: {sans: ['Inter', 'system-ui', 'sans-serif']}}}};</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', system-ui, sans-serif;
            transition: background-color .3s, color .3s
        }

        .glass {
            backdrop-filter: blur(16px) saturate(180%)
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 antialiased min-h-screen">
    <div class="flex min-h-screen">
        <?php include __DIR__ . '/../includes/seller-sidebar.php'; ?>

        <main class="flex-1 lg:ml-64 min-w-0">
            <header
                class="sticky top-0 z-30 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
                <div class="h-20 px-4 sm:px-8 flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3 min-w-0">
                        <a href="my-auctions.php"
                            class="w-10 h-10 rounded-xl bg-gray-100 dark:bg-slate-800 flex items-center justify-center"><i
                                class="fa-solid fa-arrow-left"></i></a>
                        <div class="min-w-0">
                            <div
                                class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-0.5 flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-violet-500"></span> Auction Analytics
                            </div>
                            <h1 class="text-lg sm:text-xl font-black truncate">
                                <?= htmlspecialchars($auction['title']) ?></h1>
                        </div>
                    </div>
                    <a href="../auction-details.php?id=<?= (int) $auction_id ?>"
                        class="hidden sm:inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl text-sm font-semibold">
                        <i class="fa-solid fa-eye text-xs"></i> View Live
                    </a>
                </div>
            </header>

            <div class="p-4 sm:p-6 lg:p-8 space-y-6 max-w-6xl mx-auto">

                <!-- Hero metrics -->
                <section class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Views</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-eye"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?= number_format($views) ?></div>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Watchlist</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-heart"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?= number_format($watchers) ?></div>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Total Bids</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-gavel"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?= number_format($total_bids) ?></div>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Unique
                                Bidders</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-users"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?= number_format($unique_bidders) ?></div>
                    </div>
                </section>

                <!-- Price + Conversion -->
                <section class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="bg-gradient-to-br from-indigo-600 to-violet-600 rounded-3xl p-6 text-white shadow-xl">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-white/70 mb-1">Current Bid</div>
                        <div class="text-3xl font-black tabular-nums">Rs. <?= number_format($current_bid) ?></div>
                        <div class="text-[11px] text-white/80 mt-1">
                            Starting: Rs. <?= number_format($start_price) ?>
                            <?php if ($price_growth != 0): ?>
                                <span class="ml-1 px-1.5 py-0.5 rounded bg-white/20 text-white text-[10px] font-bold">
                                    <?= $price_growth > 0 ? '+' : '' ?>    <?= $price_growth ?>%
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-3xl p-6 border border-gray-200 dark:border-gray-800">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">Conversion</div>
                        <div class="text-3xl font-black tabular-nums"><?= $conversion ?>%</div>
                        <div class="text-[11px] text-gray-500 mt-1">Bids per view</div>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-3xl p-6 border border-gray-200 dark:border-gray-800">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">Last Bid</div>
                        <div class="text-lg font-black">
                            <?= $last_bid_time ? time_ago_short($last_bid_time) : 'No bids yet' ?></div>
                        <div class="text-[11px] text-gray-500 mt-1">
                            Highest: Rs. <?= number_format($highest_bid) ?>
                        </div>
                    </div>
                </section>

                <!-- Charts -->
                <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Views chart -->
                    <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-6">
                        <h3 class="font-bold text-sm mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-chart-area text-indigo-500"></i> Views (Last 7 Days)
                        </h3>
                        <div class="space-y-3">
                            <?php foreach ($viewsChart as $row):
                                $pct = ($row['count'] / $maxViews) * 100; ?>
                                <div>
                                    <div class="flex justify-between text-xs mb-1">
                                        <span class="font-bold"><?= $row['day'] ?></span>
                                        <span class="tabular-nums text-gray-500"><?= $row['count'] ?></span>
                                    </div>
                                    <div class="h-2 rounded-full bg-gray-100 dark:bg-slate-800 overflow-hidden">
                                        <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-500 transition-all duration-700"
                                            style="width:<?= max(3, $pct) ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <!-- Bids chart -->
                    <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-6">
                        <h3 class="font-bold text-sm mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-chart-line text-violet-500"></i> Bids (Last 7 Days)
                        </h3>
                        <div class="space-y-3">
                            <?php foreach ($bidsChart as $row):
                                $pct = ($row['count'] / $maxBids) * 100; ?>
                                <div>
                                    <div class="flex justify-between text-xs mb-1">
                                        <span class="font-bold"><?= $row['day'] ?></span>
                                        <span class="tabular-nums text-gray-500"><?= $row['count'] ?></span>
                                    </div>
                                    <div class="h-2 rounded-full bg-gray-100 dark:bg-slate-800 overflow-hidden">
                                        <div class="h-full rounded-full bg-gradient-to-r from-violet-500 to-fuchsia-500 transition-all duration-700"
                                            style="width:<?= max(3, $pct) ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <!-- Recent bids (anonymous) -->
                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 overflow-hidden">
                    <div class="p-5 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                        <span
                            class="w-10 h-10 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-sm"><i
                                class="fa-solid fa-list-ol"></i></span>
                        <div>
                            <h3 class="font-bold">Recent Bid Activity</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Buyer identities are kept private
                            </p>
                        </div>
                    </div>
                    <?php if (!empty($recentBids)): ?>
                        <div class="divide-y divide-gray-100 dark:divide-gray-800">
                            <?php foreach ($recentBids as $i => $b): ?>
                                <div class="px-5 py-3 flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs font-bold">
                                            #<?= $i + 1 ?></div>
                                        <div>
                                            <div class="text-sm font-bold">Anonymous Bidder</div>
                                            <div class="text-[10px] text-gray-400"><?= time_ago_short($b['bid_time']) ?></div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-sm font-black tabular-nums">Rs.
                                            <?= number_format((float) $b['bid_amount']) ?></div>
                                        <?php if ($i === 0): ?>
                                            <div class="text-[10px] text-emerald-600 dark:text-emerald-400 font-bold">Highest</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="p-8 text-center text-sm text-gray-500">No bids yet on this auction.</div>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </div>
<?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>