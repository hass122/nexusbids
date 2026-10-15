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

/* ============================================================
   STEP 1: Detect reviews table columns dynamically
   ============================================================ */
$has_reviews_table = false;
$reviews_cols = [];
$col_map = [
    'buyer_id'      => null,   // name of column in reviews pointing to reviewer
    'auction_id'    => null,
    'seller_id'     => null,
    'rating'        => null,
    'comment'       => null,
    'created_at'    => null,
    'seller_reply'  => null,
    'seller_replied_at' => null,
];

try {
    $tbl = $conn->query("SHOW TABLES LIKE 'reviews'");
    if ($tbl && $tbl->num_rows > 0) {
        $has_reviews_table = true;
        $cols_res = $conn->query("SHOW COLUMNS FROM reviews");
        while ($c = $cols_res->fetch_assoc()) {
            $reviews_cols[] = $c['Field'];
        }

        // Detect flexible names
        $pick = function($candidates) use ($reviews_cols) {
            foreach ($candidates as $c) {
                if (in_array($c, $reviews_cols, true)) return $c;
            }
            return null;
        };

        $col_map['buyer_id']         = $pick(['buyer_id','user_id','reviewer_id','from_user_id']);
        $col_map['auction_id']       = $pick(['auction_id','item_id','listing_id']);
        $col_map['seller_id']        = $pick(['seller_id','to_user_id','receiver_id']);
        $col_map['rating']           = $pick(['rating','stars','score']);
        $col_map['comment']          = $pick(['comment','review','text','message']);
        $col_map['created_at']       = $pick(['created_at','date','created']);
        $col_map['seller_reply']     = $pick(['seller_reply','reply','response']);
        $col_map['seller_replied_at']= $pick(['seller_replied_at','reply_at','replied_at']);
    }
} catch (Throwable $e) {
    error_log("reviews schema detection failed: " . $e->getMessage());
    $has_reviews_table = false;
}

// If critical columns are missing, disable reviews feature safely
$reviews_usable = $has_reviews_table
    && $col_map['seller_id']
    && $col_map['buyer_id']
    && $col_map['rating'];

/* ============================================================
   STEP 2: Handle seller reply (only if reply column exists)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'reply_review'
    && $col_map['seller_reply'] && $col_map['seller_replied_at']) {

    nb_csrf_check();
    $review_id = intval($_POST['review_id'] ?? 0);
    $reply     = trim($_POST['reply'] ?? '');

    if ($review_id > 0 && $reply !== '' && mb_strlen($reply) <= 1000) {
        try {
            $chk = $conn->prepare("SELECT review_id FROM reviews WHERE review_id = ? AND {$col_map['seller_id']} = ?");
            $chk->bind_param("ii", $review_id, $seller_id);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $sql = "UPDATE reviews SET {$col_map['seller_reply']} = ?, {$col_map['seller_replied_at']} = NOW() WHERE review_id = ? AND {$col_map['seller_id']} = ?";
                $upd = $conn->prepare($sql);
                $upd->bind_param("sii", $reply, $review_id, $seller_id);
                $upd->execute();
                $upd->close();
                $_SESSION['success'] = "Reply posted.";
            }
            $chk->close();
        } catch (Throwable $e) {
            error_log("review reply failed: " . $e->getMessage());
            $_SESSION['error'] = "Could not save reply.";
        }
    } else {
        $_SESSION['error'] = "Reply must be 1–1000 characters.";
    }
    header("Location: reviews.php");
    exit();
}

/* ============================================================
   STEP 3: Seller info + sidebar
   ============================================================ */
$s = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ?");
$s->bind_param("i", $seller_id);
$s->execute();
$seller_info = $s->get_result()->fetch_assoc();
$s->close();

$full_name = $seller_info["full_name"] ?? "Seller";
$avatar    = $seller_info["avatar"] ?? "";
$initials  = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));
$unread_notifications = nb_unread_count($conn, $seller_id);

$sidebar_total   = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id")->fetch_assoc()["t"] ?? 0;
$sidebar_active  = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'active'")->fetch_assoc()["t"] ?? 0;
$sidebar_pending = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'pending'")->fetch_assoc()["t"] ?? 0;
$sidebar_drafts  = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'draft'")->fetch_assoc()["t"] ?? 0;

/* ============================================================
   STEP 4: Load reviews
   ============================================================ */
$total_reviews = 0;
$avg_rating = 0;
$distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
$reviews = [];
$filtered_total = 0;
$total_pages = 1;
$page = 1;
$q = '';
$filter = 0;

if ($reviews_usable) {
    try {
        $seller_col   = $col_map['seller_id'];
        $buyer_col    = $col_map['buyer_id'];
        $rating_col   = $col_map['rating'];
        $comment_col  = $col_map['comment'];
        $auction_col  = $col_map['auction_id'];
        $created_col  = $col_map['created_at'] ?? 'created_at';
        $reply_col    = $col_map['seller_reply'];
        $reply_at_col = $col_map['seller_replied_at'];

        /* Overview */
        $ov = $conn->query("
            SELECT COUNT(*) AS cnt, COALESCE(AVG($rating_col), 0) AS avg
            FROM reviews WHERE $seller_col = $seller_id
        ")->fetch_assoc();
        $total_reviews = intval($ov['cnt'] ?? 0);
        $avg_rating = round(floatval($ov['avg'] ?? 0), 1);

        /* Distribution */
        $dist = $conn->query("
            SELECT $rating_col AS rating, COUNT(*) AS cnt
            FROM reviews WHERE $seller_col = $seller_id
            GROUP BY $rating_col
        ");
        while ($r = $dist->fetch_assoc()) {
            $distribution[(int) $r['rating']] = intval($r['cnt']);
        }

        /* Filters */
        $q      = trim($_GET['q'] ?? '');
        $filter = intval($_GET['rating'] ?? 0);
        $page   = max(1, intval($_GET['page'] ?? 1));
        $per_page = 10;

        $where = ["r.`$seller_col` = ?"];
        $params = [$seller_id];
        $types = "i";

        if ($q !== '' && $comment_col) {
            $where[] = "(r.`$comment_col` LIKE ? OR u.full_name LIKE ?)";
            $like = '%' . $q . '%';
            array_push($params, $like, $like);
            $types .= "ss";
        }
        if ($filter >= 1 && $filter <= 5) {
            $where[] = "r.`$rating_col` = ?";
            $params[] = $filter;
            $types .= "i";
        }

        $where_sql = implode(' AND ', $where);

        /* Count */
        $cnt_sql = "SELECT COUNT(*) AS t FROM reviews r
                    INNER JOIN users u ON r.`$buyer_col` = u.user_id
                    WHERE $where_sql";
        $cs = $conn->prepare($cnt_sql);
        $cs->bind_param($types, ...$params);
        $cs->execute();
        $filtered_total = intval($cs->get_result()->fetch_assoc()['t'] ?? 0);
        $cs->close();

        $total_pages = max(1, (int) ceil($filtered_total / $per_page));
        $page = min($page, $total_pages);
        $offset = ($page - 1) * $per_page;

        /* List — build optional fields */
        $auction_select = $auction_col ? ", a.title AS auction_title, a.auction_id AS auction_id" : ", NULL AS auction_title, NULL AS auction_id";
        $auction_join   = $auction_col ? "LEFT JOIN auctions a ON r.`$auction_col` = a.auction_id" : "";
        $reply_select   = ($reply_col ? ", r.`$reply_col` AS seller_reply" : ", NULL AS seller_reply")
                        . ($reply_at_col ? ", r.`$reply_at_col` AS seller_replied_at" : ", NULL AS seller_replied_at");
        $comment_select = $comment_col ? ", r.`$comment_col` AS comment" : ", NULL AS comment";

        $list_sql = "SELECT r.review_id,
                            r.`$rating_col` AS rating,
                            r.`$created_col` AS created_at
                            $comment_select
                            $reply_select
                            $auction_select,
                            u.full_name AS buyer_name,
                            u.avatar AS buyer_avatar
                     FROM reviews r
                     INNER JOIN users u ON r.`$buyer_col` = u.user_id
                     $auction_join
                     WHERE $where_sql
                     ORDER BY r.`$created_col` DESC
                     LIMIT ? OFFSET ?";
        $params[] = $per_page;
        $params[] = $offset;
        $types .= "ii";

        $ls = $conn->prepare($list_sql);
        $ls->bind_param($types, ...$params);
        $ls->execute();
        $reviews = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
        $ls->close();

    } catch (Throwable $e) {
        error_log("reviews load failed: " . $e->getMessage());
        $reviews = [];
    }
}

function time_ago_short($dt) {
    if (empty($dt)) return '';
    $diff = time() - strtotime($dt);
    if ($diff < 60) return "just now";
    if ($diff < 3600) return intdiv($diff, 60) . "m ago";
    if ($diff < 86400) return intdiv($diff, 3600) . "h ago";
    if ($diff < 604800) return intdiv($diff, 86400) . "d ago";
    return date("d M Y", strtotime($dt));
}
function build_url(array $overrides = []) {
    return '?' . http_build_query(array_merge($_GET, $overrides));
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reviews · Seller · NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = {darkMode: 'class', theme: {extend: {fontFamily: {sans: ['Inter', 'system-ui', 'sans-serif']}}}};</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; transition: background-color .3s, color .3s; }
        .glass { backdrop-filter: blur(16px) saturate(180%); }
        .custom-select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L2 4h8z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right .75rem center; appearance: none; }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 antialiased min-h-screen">
    <div class="flex min-h-screen">

        <?php include __DIR__ . '/../includes/seller-sidebar.php'; ?>

        <main class="flex-1 lg:ml-64 min-w-0">

            <header class="sticky top-0 z-30 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
                <div class="h-20 px-4 sm:px-8 flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3 min-w-0">
                        <button onclick="document.getElementById('sellerSidebar').classList.remove('-translate-x-full'); document.getElementById('sidebarOverlay').classList.remove('hidden')" class="lg:hidden w-10 h-10 rounded-xl bg-gray-100 dark:bg-slate-800 flex items-center justify-center">
                            <i class="fa-solid fa-bars"></i>
                        </button>
                        <div class="min-w-0">
                            <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-0.5 flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-violet-500"></span> Seller Panel
                            </div>
                            <h1 class="text-lg sm:text-xl font-black truncate">Reviews & Ratings</h1>
                        </div>
                    </div>
                    <button id="themeToggle" class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition-all">
                        <i class="fa-solid fa-moon dark:hidden"></i><i class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                    </button>
                </div>
            </header>

            <div class="p-4 sm:p-6 lg:p-8 space-y-6 max-w-6xl mx-auto">

                <?php if (!empty($_SESSION['success'])): ?>
                    <div class="flex items-start gap-3 p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30">
                        <i class="fa-solid fa-circle-check text-emerald-600 dark:text-emerald-400 mt-1"></i>
                        <p class="text-sm text-emerald-700 dark:text-emerald-300 font-medium self-center">
                            <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                        </p>
                    </div>
                <?php endif; ?>
                <?php if (!empty($_SESSION['error'])): ?>
                    <div class="flex items-start gap-3 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30">
                        <i class="fa-solid fa-circle-exclamation text-rose-600 dark:text-rose-400 mt-1"></i>
                        <p class="text-sm text-rose-700 dark:text-rose-300 font-medium self-center">
                            <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <?php if (!$has_reviews_table): ?>
                    <!-- Table doesn't exist -->
                    <div class="p-8 rounded-3xl bg-amber-500/10 border border-amber-500/30 text-center">
                        <div class="w-16 h-16 mx-auto rounded-2xl bg-amber-500/20 text-amber-600 dark:text-amber-400 flex items-center justify-center text-2xl mb-4">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                        </div>
                        <h3 class="font-bold">Reviews table not found</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">Please run the migration SQL first.</p>
                    </div>

                <?php elseif (!$reviews_usable): ?>
                    <!-- Table exists but missing key columns -->
                    <div class="p-8 rounded-3xl bg-rose-500/10 border border-rose-500/30">
                        <div class="flex items-start gap-3 mb-4">
                            <div class="w-12 h-12 rounded-xl bg-rose-500/20 text-rose-600 dark:text-rose-400 flex items-center justify-center text-xl shrink-0">
                                <i class="fa-solid fa-database"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-lg">Reviews table schema mismatch</h3>
                                <p class="text-sm text-gray-700 dark:text-gray-300 mt-1">Your reviews table is missing some expected columns. Detected columns:</p>
                            </div>
                        </div>

                        <div class="p-4 rounded-xl bg-white/50 dark:bg-slate-900/50 border border-rose-500/20 mb-4">
                            <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-2">Columns found in reviews table:</div>
                            <div class="flex flex-wrap gap-1.5">
                                <?php foreach ($reviews_cols as $c): ?>
                                    <span class="px-2 py-0.5 rounded bg-white dark:bg-slate-800 border border-gray-200 dark:border-gray-700 text-[11px] font-mono"><?= htmlspecialchars($c) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="p-4 rounded-xl bg-blue-500/10 border border-blue-500/20">
                            <div class="text-xs font-bold text-blue-700 dark:text-blue-300 mb-2 flex items-center gap-1.5">
                                <i class="fa-solid fa-wrench"></i> Fix: Run this SQL in phpMyAdmin
                            </div>
                            <pre class="text-[10px] leading-relaxed bg-white dark:bg-slate-900 p-3 rounded overflow-x-auto font-mono text-gray-700 dark:text-gray-300"><?php
echo htmlspecialchars("
-- Rename/add missing columns (pick ONE that matches your data):
ALTER TABLE reviews
  ADD COLUMN IF NOT EXISTS buyer_id INT NULL,
  ADD COLUMN IF NOT EXISTS auction_id INT NULL,
  ADD COLUMN IF NOT EXISTS seller_reply TEXT NULL,
  ADD COLUMN IF NOT EXISTS seller_replied_at DATETIME NULL;

-- If reviewer column is named differently, copy it:
-- UPDATE reviews SET buyer_id = user_id WHERE buyer_id IS NULL;
-- UPDATE reviews SET buyer_id = reviewer_id WHERE buyer_id IS NULL;
");
?></pre>
                        </div>

                        <p class="text-xs text-gray-600 dark:text-gray-400 mt-4">
                            After running the SQL, refresh this page. The reviews panel will work automatically.
                        </p>
                    </div>

                <?php else: ?>

                    <!-- Overview -->
                    <section class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                        <div class="grid grid-cols-1 md:grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-gray-100 dark:divide-gray-800">
                            <div class="p-8 text-center">
                                <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-3">Average Rating</div>
                                <div class="text-5xl font-black text-gray-900 dark:text-white tabular-nums">
                                    <?= $total_reviews > 0 ? number_format($avg_rating, 1) : '—' ?>
                                </div>
                                <div class="flex items-center justify-center gap-0.5 mt-3">
                                    <?php for ($i = 1; $i <= 5; $i++):
                                        $filled = $i <= round($avg_rating); ?>
                                        <i class="fa-solid fa-star text-lg <?= $filled ? 'text-amber-400' : 'text-gray-200 dark:text-slate-700' ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-400 mt-3">
                                    Based on <strong class="text-gray-900 dark:text-white"><?= number_format($total_reviews) ?></strong>
                                    review<?= $total_reviews === 1 ? '' : 's' ?>
                                </div>
                            </div>
                            <div class="md:col-span-2 p-6 sm:p-8">
                                <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-4">Rating Distribution</div>
                                <div class="space-y-3">
                                    <?php for ($star = 5; $star >= 1; $star--):
                                        $cnt = $distribution[$star] ?? 0;
                                        $pct = $total_reviews > 0 ? ($cnt / $total_reviews) * 100 : 0;
                                    ?>
                                        <div class="flex items-center gap-3">
                                            <span class="w-8 text-xs font-bold text-gray-700 dark:text-gray-300 flex items-center gap-1">
                                                <?= $star ?><i class="fa-solid fa-star text-amber-400 text-[10px]"></i>
                                            </span>
                                            <div class="flex-1 h-2 rounded-full bg-gray-100 dark:bg-slate-800 overflow-hidden">
                                                <div class="h-full rounded-full bg-gradient-to-r from-amber-400 to-amber-500 transition-all duration-700" style="width:<?= $pct ?>%"></div>
                                            </div>
                                            <span class="w-12 text-right text-xs font-bold tabular-nums text-gray-700 dark:text-gray-300"><?= $cnt ?></span>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Filters -->
                    <form method="GET" class="flex flex-col sm:flex-row justify-between items-center gap-3">
                        <div class="relative w-full sm:w-80">
                            <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search reviews or buyers..."
                                class="w-full pl-10 pr-4 py-2.5 bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div class="flex items-center gap-2 w-full sm:w-auto">
                            <select name="rating" onchange="this.form.submit()" class="custom-select flex-1 sm:flex-initial py-2.5 pl-4 pr-9 bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 rounded-xl text-sm">
                                <option value="0" <?= $filter === 0 ? 'selected' : '' ?>>All Ratings</option>
                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                    <option value="<?= $i ?>" <?= $filter === $i ? 'selected' : '' ?>><?= $i ?> Star<?= $i === 1 ? '' : 's' ?></option>
                                <?php endfor; ?>
                            </select>
                            <button type="submit" class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold">
                                <i class="fa-solid fa-filter"></i>
                            </button>
                            <?php if ($q !== '' || $filter > 0): ?>
                                <a href="reviews.php" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-slate-800 text-xs font-bold hover:bg-gray-200 dark:hover:bg-slate-700">
                                    <i class="fa-solid fa-rotate-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <!-- List -->
                    <?php if (empty($reviews)): ?>
                        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-16 text-center">
                            <div class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-amber-500/10 to-rose-500/10 text-amber-500 flex items-center justify-center text-3xl mb-5">
                                <i class="fa-regular fa-star"></i>
                            </div>
                            <h3 class="text-xl font-black">No reviews found</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2 max-w-md mx-auto">
                                <?= ($q !== '' || $filter > 0) ? 'Try adjusting your filters.' : 'Reviews will appear here after buyers rate you.' ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($reviews as $r):
                                $buyer_initials = strtoupper(substr(preg_replace('/\s+/', '', $r['buyer_name'] ?? 'B'), 0, 2));
                            ?>
                                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 sm:p-6 hover:border-indigo-500/40 transition-all">
                                    <div class="flex items-start gap-4">
                                        <div class="shrink-0">
                                            <?php if (!empty($r['buyer_avatar'])): ?>
                                                <img src="../uploads/avatars/<?= htmlspecialchars($r['buyer_avatar']) ?>" class="w-12 h-12 rounded-xl object-cover" alt="">
                                            <?php else: ?>
                                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-sm flex items-center justify-center">
                                                    <?= htmlspecialchars($buyer_initials) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="flex-1 min-w-0">
                                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-2">
                                                <div>
                                                    <div class="font-bold text-gray-900 dark:text-white"><?= htmlspecialchars($r['buyer_name'] ?? 'Buyer') ?></div>
                                                    <div class="text-[11px] text-gray-500 dark:text-gray-400"><?= time_ago_short($r['created_at'] ?? '') ?></div>
                                                </div>
                                                <div class="flex items-center gap-0.5">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <i class="fa-solid fa-star text-sm <?= $i <= (int) $r['rating'] ? 'text-amber-400' : 'text-gray-200 dark:text-slate-700' ?>"></i>
                                                    <?php endfor; ?>
                                                </div>
                                            </div>

                                            <?php if (!empty($r['comment'])): ?>
                                                <p class="text-sm text-gray-700 dark:text-gray-300 leading-relaxed mt-2"><?= nl2br(htmlspecialchars($r['comment'])) ?></p>
                                            <?php endif; ?>

                                            <?php if (!empty($r['auction_title'])): ?>
                                                <a href="../auction-details.php?id=<?= (int) $r['auction_id'] ?>" class="inline-flex items-center gap-1.5 mt-3 text-[11px] font-bold text-indigo-600 dark:text-indigo-400 hover:underline">
                                                    <i class="fa-solid fa-box text-[9px]"></i> <?= htmlspecialchars($r['auction_title']) ?>
                                                </a>
                                            <?php endif; ?>

                                            <!-- Seller Reply -->
                                            <?php if (!empty($r['seller_reply'])): ?>
                                                <div class="mt-4 ml-2 pl-4 border-l-2 border-indigo-500/40">
                                                    <div class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-wider text-indigo-600 dark:text-indigo-400 mb-1">
                                                        <i class="fa-solid fa-store text-[9px]"></i>
                                                        Your Reply · <?= time_ago_short($r['seller_replied_at']) ?>
                                                    </div>
                                                    <p class="text-xs text-gray-600 dark:text-gray-300 leading-relaxed"><?= nl2br(htmlspecialchars($r['seller_reply'])) ?></p>
                                                </div>
                                            <?php elseif ($col_map['seller_reply']): ?>
                                                <button onclick="document.getElementById('reply_<?= (int)$r['review_id'] ?>').classList.toggle('hidden')"
                                                    class="mt-3 text-[11px] font-bold text-indigo-600 dark:text-indigo-400 hover:underline flex items-center gap-1">
                                                    <i class="fa-solid fa-reply text-[9px]"></i> Reply to Review
                                                </button>
                                                <form method="POST" id="reply_<?= (int)$r['review_id'] ?>" class="hidden mt-3 space-y-2">
                                                    <?php nb_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="reply_review">
                                                    <input type="hidden" name="review_id" value="<?= (int)$r['review_id'] ?>">
                                                    <textarea name="reply" required maxlength="1000" rows="2" placeholder="Write your response..."
                                                        class="w-full px-3 py-2 rounded-xl bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 text-xs resize-none focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                                                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold">
                                                        <i class="fa-solid fa-paper-plane mr-1"></i> Send Reply
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="flex items-center justify-center gap-1.5">
                                <?php if ($page > 1): ?>
                                    <a href="<?= htmlspecialchars(build_url(['page' => $page - 1])) ?>"
                                        class="w-9 h-9 rounded-xl flex items-center justify-center bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 hover:bg-indigo-100 dark:hover:bg-slate-700">
                                        <i class="fa-solid fa-chevron-left text-xs"></i>
                                    </a>
                                <?php endif; ?>
                                <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++):
                                    $is = $p === $page; ?>
                                    <a href="<?= htmlspecialchars(build_url(['page' => $p])) ?>"
                                        class="w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold transition-all <?= $is ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 hover:bg-indigo-100 dark:hover:bg-slate-700' ?>"><?= $p ?></a>
                                <?php endfor; ?>
                                <?php if ($page < $total_pages): ?>
                                    <a href="<?= htmlspecialchars(build_url(['page' => $page + 1])) ?>"
                                        class="w-9 h-9 rounded-xl flex items-center justify-center bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 hover:bg-indigo-100 dark:hover:bg-slate-700">
                                        <i class="fa-solid fa-chevron-right text-xs"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <footer class="mt-8 border-t border-gray-200 dark:border-gray-800 px-4 sm:px-8 py-6">
                <div class="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
                    <p>© <?= date("Y") ?> NexusBids Seller Panel.</p>
                </div>
            </footer>
        </main>
    </div>

    <script>
        (function () {
            const html = document.documentElement, t = document.getElementById('themeToggle'), s = localStorage.getItem('theme');
            if (s === 'dark' || (!s && window.matchMedia('(prefers-color-scheme: dark)').matches)) html.classList.add('dark');
            t?.addEventListener('click', () => { html.classList.toggle('dark'); localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light') });
        })();
    </script>
<?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>