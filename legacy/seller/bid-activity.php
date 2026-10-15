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

$s = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ?");
$s->bind_param("i", $seller_id);
$s->execute();
$seller_info = $s->get_result()->fetch_assoc();
$s->close();
$full_name = $seller_info["full_name"] ?? "Seller";
$avatar = $seller_info["avatar"] ?? "";
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));
$unread_notifications = nb_unread_count($conn, $seller_id);

$sidebar_total = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id")->fetch_assoc()["t"] ?? 0;
$sidebar_active = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status='active'")->fetch_assoc()["t"] ?? 0;
$sidebar_pending = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status='pending'")->fetch_assoc()["t"] ?? 0;
$sidebar_drafts = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status='draft'")->fetch_assoc()["t"] ?? 0;

/* Filters */
$auction_filter = intval($_GET['auction'] ?? 0);
$range = $_GET['range'] ?? '7d';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 25;

$where = ["a.seller_id = ?"];
$params = [$seller_id];
$types = "i";

if ($auction_filter > 0) {
    $where[] = "b.auction_id = ?";
    $params[] = $auction_filter;
    $types .= "i";
}
if ($range === '1d')
    $where[] = "b.bid_time >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
if ($range === '7d')
    $where[] = "b.bid_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
if ($range === '30d')
    $where[] = "b.bid_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

$where_sql = implode(' AND ', $where);

$cnt_sql = "SELECT COUNT(*) AS t FROM bids b INNER JOIN auctions a ON b.auction_id = a.auction_id WHERE $where_sql";
$cs = $conn->prepare($cnt_sql);
$cs->bind_param($types, ...$params);
$cs->execute();
$total = intval($cs->get_result()->fetch_assoc()['t'] ?? 0);
$cs->close();

$total_pages = max(1, (int) ceil($total / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$sql = "SELECT b.bid_id, b.bid_amount, b.bid_time, b.bidder_id,
               a.auction_id, a.title AS auction_title, a.image AS auction_image,
               a.current_bid, a.starting_price
        FROM bids b
        INNER JOIN auctions a ON b.auction_id = a.auction_id
        WHERE $where_sql
        ORDER BY b.bid_time DESC
        LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= "ii";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$bids = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* My auctions for filter dropdown */
$my_auctions = $conn->query("SELECT auction_id, title FROM auctions WHERE seller_id = $seller_id ORDER BY created_at DESC LIMIT 50")->fetch_all(MYSQLI_ASSOC);

/* Aggregate stats */
$stats = $conn->query("
    SELECT COUNT(*) AS total_bids, COUNT(DISTINCT b.bidder_id) AS unique_bidders,
           COALESCE(SUM(b.bid_amount),0) AS total_value
    FROM bids b INNER JOIN auctions a ON b.auction_id = a.auction_id
    WHERE a.seller_id = $seller_id AND b.bid_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
")->fetch_assoc();

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
function build_url(array $o = [])
{
    return '?' . http_build_query(array_merge($_GET, $o));
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bid Activity · NexusBids</title>
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

        .data-row:hover {
            background-color: rgb(238 242 255/.5)
        }

        .dark .data-row:hover {
            background-color: rgb(30 41 59/.5)
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
                    <div>
                        <div
                            class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-0.5 flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-violet-500"></span> Seller Panel
                        </div>
                        <h1 class="text-lg sm:text-xl font-black truncate">Bid Activity</h1>
                    </div>
                    <button id="themeToggle"
                        class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                        <i class="fa-solid fa-moon dark:hidden"></i><i
                            class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                    </button>
                </div>
            </header>

            <div class="p-4 sm:p-6 lg:p-8 space-y-6 max-w-6xl mx-auto">

                <section>
                    <h2 class="text-2xl sm:text-3xl font-black tracking-tight flex items-center gap-3">
                        <span
                            class="w-11 h-11 rounded-2xl bg-gradient-to-br from-violet-500 to-fuchsia-500 flex items-center justify-center text-white shadow-lg">
                            <i class="fa-solid fa-gavel text-base"></i>
                        </span>
                        Bid Activity
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Track all incoming bids across your
                        auctions.</p>
                </section>

                <!-- Stats -->
                <section class="grid grid-cols-3 gap-4">
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-2">Bids (7d)</div>
                        <div class="text-2xl font-black tabular-nums">
                            <?= number_format(intval($stats['total_bids'] ?? 0)) ?></div>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-2">Unique Bidders
                        </div>
                        <div class="text-2xl font-black tabular-nums">
                            <?= number_format(intval($stats['unique_bidders'] ?? 0)) ?></div>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-2">Bid Value (7d)
                        </div>
                        <div class="text-2xl font-black tabular-nums">Rs.
                            <?= number_format(floatval($stats['total_value'] ?? 0)) ?></div>
                    </div>
                </section>

                <!-- Filters -->
                <form method="GET"
                    class="flex flex-wrap items-center gap-3 bg-white dark:bg-slate-900 p-4 rounded-2xl border border-gray-200 dark:border-gray-800">
                    <select name="auction"
                        class="flex-1 min-w-[220px] px-3 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm">
                        <option value="0">All Auctions</option>
                        <?php foreach ($my_auctions as $ma): ?>
                            <option value="<?= (int) $ma['auction_id'] ?>" <?= $auction_filter === (int) $ma['auction_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ma['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="range"
                        class="px-3 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm">
                        <option value="1d" <?= $range === '1d' ? 'selected' : '' ?>>Last 24 Hours</option>
                        <option value="7d" <?= $range === '7d' ? 'selected' : '' ?>>Last 7 Days</option>
                        <option value="30d" <?= $range === '30d' ? 'selected' : '' ?>>Last 30 Days</option>
                        <option value="all" <?= $range === 'all' ? 'selected' : '' ?>>All Time</option>
                    </select>
                    <button
                        class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold">
                        <i class="fa-solid fa-filter mr-1"></i> Filter
                    </button>
                </form>

                <!-- Table -->
                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 overflow-hidden">
                    <?php if (empty($bids)): ?>
                        <div class="p-16 text-center">
                            <div
                                class="w-16 h-16 mx-auto rounded-2xl bg-gray-100 dark:bg-slate-800 text-gray-400 flex items-center justify-center text-2xl mb-4">
                                <i class="fa-solid fa-inbox"></i>
                            </div>
                            <h3 class="font-bold">No bids found</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Bid history will appear here as buyers
                                bid.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead
                                    class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                                    <tr>
                                        <th
                                            class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Bidder</th>
                                        <th
                                            class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Auction</th>
                                        <th
                                            class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Bid</th>
                                        <th
                                            class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Time</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <?php foreach ($bids as $i => $b):
                                        $is_leading = floatval($b['current_bid']) == floatval($b['bid_amount']);
                                        ?>
                                        <tr class="data-row">
                                            <td class="px-6 py-4">
                                                <div class="flex items-center gap-2">
                                                    <div
                                                        class="w-8 h-8 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-[10px] font-bold">
                                                        B<?= ($offset + $i + 1) ?></div>
                                                    <div>
                                                        <div class="text-xs font-bold">Anonymous Bidder</div>
                                                        <div class="text-[10px] text-gray-400">ID:
                                                            <?= substr(md5((string) $b['bidder_id']), 0, 6) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="flex items-center gap-3">
                                                    <?php if (!empty($b['auction_image'])): ?>
                                                        <img src="../uploads/auctions/<?= htmlspecialchars($b['auction_image']) ?>"
                                                            class="w-9 h-9 rounded-lg object-cover" alt="">
                                                    <?php endif; ?>
                                                    <a href="auction-analytics.php?id=<?= (int) $b['auction_id'] ?>"
                                                        class="text-xs font-semibold hover:text-indigo-600 line-clamp-1 max-w-[180px]">
                                                        <?= htmlspecialchars($b['auction_title']) ?>
                                                    </a>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="font-black tabular-nums text-xs">Rs.
                                                    <?= number_format((float) $b['bid_amount']) ?></div>
                                                <?php if ($is_leading): ?>
                                                    <div class="text-[10px] text-emerald-600 dark:text-emerald-400 font-bold"><i
                                                            class="fa-solid fa-crown text-[8px]"></i> Leading</div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-xs"><?= time_ago_short($b['bid_time']) ?></div>
                                                <div class="text-[10px] text-gray-400">
                                                    <?= date('d M, h:i A', strtotime($b['bid_time'])) ?></div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($total_pages > 1): ?>
                            <div
                                class="px-6 py-4 border-t border-gray-100 dark:border-gray-800 flex items-center justify-center gap-1.5">
                                <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++):
                                    $is = $p === $page; ?>
                                    <a href="<?= htmlspecialchars(build_url(['page' => $p])) ?>"
                                        class="w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold <?= $is ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-slate-800' ?>">
                                        <?= $p ?>
                                    </a>
                                <?php endfor; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </div>

    <script>
        (function () {
            const html = document.documentElement, t = document.getElementById('themeToggle'), s = localStorage.getItem('theme');
            if (s === 'dark' || (!s && window.matchMedia('(prefers-color-scheme: dark)').matches)) html.classList.add('dark');
            t?.addEventListener('click', () => {html.classList.toggle('dark'); localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light')});
        })();
    </script>
<?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>