<?php
session_start();

if (!isset($_SESSION["user_id"]) || ($_SESSION["role"] ?? '') !== 'user') {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";
require_once "../includes/review-helpers.php";

date_default_timezone_set('Asia/Karachi');

$user_id = intval($_SESSION["user_id"]);
$auction_id = intval($_GET["auction_id"] ?? $_POST["auction_id"] ?? 0);

if ($auction_id <= 0) {
    $_SESSION["flash_error"] = "Invalid auction.";
    header("Location: orders.php");
    exit();
}

/* ---------- Verify user won this auction ---------- */
$stmt = $conn->prepare("
    SELECT
        a.title,
        a.image AS auction_image,
        a.seller_id,
        u.full_name AS seller_name,
        u.email AS seller_email,
        u.avatar AS seller_avatar,
        w.winning_bid,
        w.created_at AS won_at,
        o.order_id,
        o.order_status,
        o.payment_status
    FROM winners w
    INNER JOIN auctions a ON w.auction_id = a.auction_id
    INNER JOIN users u ON a.seller_id = u.user_id
    LEFT JOIN orders o ON o.winner_id = w.winner_id
    WHERE w.auction_id = ? AND w.user_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $auction_id, $user_id);
$stmt->execute();
$win = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$win) {
    $_SESSION["flash_error"] = "You can only review auctions you've won.";
    header("Location: orders.php");
    exit();
}

/* ---------- Only allow reviews after delivery/completion ---------- */
$order_status = strtolower($win['order_status'] ?? '');
$payment_status = strtolower($win['payment_status'] ?? '');

if (!$order_status || !in_array($order_status, ['delivered', 'completed'], true)) {
    $_SESSION["flash_error"] = "You can review this seller after your order has been delivered.";
    header("Location: order-details.php?id=" . $auction_id);
    exit();
}

if ($payment_status !== 'paid') {
    $_SESSION["flash_error"] = "You can review this seller after your payment is confirmed.";
    header("Location: order-details.php?id=" . $auction_id);
    exit();
}

/* ---------- Check if already reviewed ---------- */
$already_reviewed = nb_user_has_reviewed($conn, $auction_id, $user_id);

$existing_review = null;
if ($already_reviewed) {
    $r_stmt = $conn->prepare("
        SELECT rating, review_text, created_at
        FROM reviews
        WHERE auction_id = ? AND reviewer_id = ?
        LIMIT 1
    ");
    $r_stmt->bind_param("ii", $auction_id, $user_id);
    $r_stmt->execute();
    $existing_review = $r_stmt->get_result()->fetch_assoc();
    $r_stmt->close();
}

/* ---------- User info for navbar ---------- */
$u_stmt = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ?");
$u_stmt->bind_param("i", $user_id);
$u_stmt->execute();
$user_info = $u_stmt->get_result()->fetch_assoc();
$u_stmt->close();

$user_name = $user_info["full_name"] ?? "User";
$user_avatar = $user_info["avatar"] ?? "";
$user_initials = strtoupper(substr(preg_replace('/\s+/', '', $user_name), 0, 2));
$unread_notifications = nb_unread_count($conn, $user_id);

/* ---------- Seller rating ---------- */
$seller_rating = nb_seller_rating($conn, $win["seller_id"]);
$seller_avg_rating = $seller_rating['avg'];
$seller_review_count = $seller_rating['count'];
$seller_initials = strtoupper(substr(preg_replace('/\s+/', '', $win["seller_name"]), 0, 2));

/* ---------- Handle POST ---------- */
$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && !$already_reviewed) {
    nb_csrf_check();

    $rating = intval($_POST["rating"] ?? 0);
    $comment = trim($_POST["comment"] ?? "");

    if (mb_strlen($comment) > 1000) {
        $comment = mb_substr($comment, 0, 1000);
    }

    if ($rating < 1 || $rating > 5) {
        $error = "Please select a rating between 1 and 5 stars.";
    } else {
        $insert = $conn->prepare("
            INSERT INTO reviews
                (seller_id, auction_id, reviewer_id, reviewee_id, rating, review_text)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insert->bind_param(
            "iiiiss",
            $win["seller_id"],
            $auction_id,
            $user_id,
            $win["seller_id"],
            $rating,
            $comment
        );

        if ($insert->execute()) {
            $insert->close();

            /* Recalculate seller rating */
            nb_recalculate_seller_rating($conn, $win["seller_id"]);

            /* Notify seller */
            if (function_exists('nb_notify')) {
                try {
                    nb_notify(
                        $conn,
                        $win["seller_id"],
                        "review",
                        "New review received",
                        "You received a $rating-star review for \"" . $win["title"] . "\".",
                        "seller/reviews.php"
                    );
                } catch (Throwable $e) {
                }
            }

            $success = "Thank you for your feedback!";
            $already_reviewed = true;

            $existing_review = [
                'rating' => $rating,
                'review_text' => $comment,
                'created_at' => date('Y-m-d H:i:s'),
            ];
        } else {
            $insert->close();
            $error = "Something went wrong. Please try again.";
        }
    }
}

function time_ago_short($datetime)
{
    if (empty($datetime))
        return '';
    $diff = time() - strtotime($datetime);
    if ($diff < 60)
        return "just now";
    if ($diff < 3600)
        return intdiv($diff, 60) . "m ago";
    if ($diff < 86400)
        return intdiv($diff, 3600) . "h ago";
    if ($diff < 604800)
        return intdiv($diff, 86400) . "d ago";
    return date("d M Y", strtotime($datetime));
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Review | NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = {darkMode: 'class', theme: {extend: {fontFamily: {sans: ['Inter', 'system-ui', 'sans-serif']}, animation: {'pulse-soft': 'pulseSoft 2s ease-in-out infinite'}, keyframes: {pulseSoft: {'0%, 100%': {opacity: '1'}, '50%': {opacity: '0.5'}}}}}};
        (function(){ try { const s = localStorage.getItem('theme'); const d = window.matchMedia('(prefers-color-scheme: dark)').matches; if (s === 'dark' || (!s && d)) document.documentElement.classList.add('dark'); } catch(e){} })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        html { scroll-behavior: smooth; }
        body { transition: background-color .3s ease, color .3s ease; font-feature-settings: 'cv02','cv03','cv04','cv11'; }
        .glass { backdrop-filter: blur(16px) saturate(180%); -webkit-backdrop-filter: blur(16px) saturate(180%); }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .dark ::-webkit-scrollbar-thumb { background: #334155; }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; } }
        .star-rating { display: flex; flex-direction: row-reverse; justify-content: flex-end; gap: 6px; }
        .star-rating input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
        .star-rating label { cursor: pointer; font-size: 2.5rem; line-height: 1; color: #d1d5db; transition: transform .15s ease, color .15s ease; }
        .dark .star-rating label { color: #475569; }
        .star-rating label:hover, .star-rating label:hover ~ label, .star-rating input[type="radio"]:checked ~ label { color: #fbbf24; }
        .star-rating label:hover { transform: scale(1.15); }
    </style>
</head>
<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 font-sans antialiased min-h-screen">

    <!-- Top Utility Bar -->
    <div class="bg-gray-950 text-gray-400 text-xs py-2.5 px-4 sm:px-8 border-b border-gray-800/50">
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-2">
            <div class="flex items-center space-x-6">
                <span class="flex items-center gap-2"><i class="fa-solid fa-location-dot text-indigo-400"></i> Pakistan</span>
                <span class="hidden sm:flex items-center gap-2"><i class="fa-solid fa-phone text-indigo-400"></i> +92 300 1234567</span>
            </div>
            <div class="flex items-center space-x-6">
                <a href="../about.php" class="hover:text-white"><i class="fa-regular fa-circle-question text-indigo-400"></i> Help Center</a>
                <a href="../about.php#why-nexusbids" class="hover:text-white"><i class="fa-solid fa-shield-halved text-indigo-400"></i> Secure Bidding</a>
            </div>
        </div>
    </div>

    <!-- Main Navbar -->
    <header class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
            <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight shrink-0 group" href="../index.php">
                <span class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg group-hover:scale-105 transition-all"><i class="fa-solid fa-gavel text-lg"></i></span>
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
                <button id="themeToggle" class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition">
                    <i class="fa-solid fa-moon dark:hidden"></i><i class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                </button>
                <a href="../notifications.php" class="relative w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($unread_notifications > 0): ?><span class="absolute top-1.5 right-1.5 w-2.5 h-2.5 rounded-full bg-rose-500 border-2 border-white dark:border-gray-800 animate-pulse-soft"></span><?php endif; ?>
                </a>
                <div class="flex items-center gap-3 pl-3 border-l border-gray-200 dark:border-gray-800">
                    <div class="relative">
                        <?php if (!empty($user_avatar)): ?>
                                <img src="../uploads/avatars/<?= htmlspecialchars($user_avatar) ?>" class="w-10 h-10 rounded-full object-cover ring-2 ring-white dark:ring-slate-900" alt="">
                        <?php else: ?>
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center shadow-lg ring-2 ring-white dark:ring-slate-900"><?= htmlspecialchars($user_initials) ?></div>
                        <?php endif; ?>
                        <span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-emerald-500 border-2 border-white dark:border-slate-900"></span>
                    </div>
                    <div class="hidden sm:block text-left">
                        <div class="text-sm font-bold leading-tight truncate max-w-32"><?= htmlspecialchars($user_name) ?></div>
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

    <main class="max-w-5xl mx-auto px-4 sm:px-8 py-8">

        <!-- Breadcrumb -->
        <nav class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mb-6">
            <a href="dashboard.php" class="hover:text-indigo-600">Dashboard</a>
            <i class="fa-solid fa-chevron-right text-[8px]"></i>
            <a href="orders.php" class="hover:text-indigo-600">My Orders</a>
            <i class="fa-solid fa-chevron-right text-[8px]"></i>
            <span class="text-gray-900 dark:text-white font-semibold">Add Review</span>
        </nav>

        <?php if (!empty($error)): ?>
                <div class="mb-6 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-sm text-rose-700 dark:text-rose-300 flex items-center gap-3">
                    <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
                </div>
        <?php endif; ?>

        <div class="mb-8">
            <h1 class="text-3xl font-black tracking-tight flex items-center gap-3">
                <span class="w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-500 flex items-center justify-center text-white shadow-lg"><i class="fa-solid fa-star text-lg"></i></span>
                <?= $already_reviewed ? 'Your Review' : 'Rate Your Experience' ?>
            </h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                <?= $already_reviewed ? 'Thank you for your feedback.' : 'Your feedback helps other buyers and supports great sellers.' ?>
            </p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

            <!-- LEFT: Item + Seller -->
            <div class="lg:col-span-5 space-y-5">
                <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <div class="relative aspect-video bg-gray-100 dark:bg-slate-800 overflow-hidden">
                        <?php if (!empty($win['auction_image'])): ?>
                                <img src="../uploads/auctions/<?= htmlspecialchars($win['auction_image']) ?>" class="w-full h-full object-cover" alt="">
                        <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center text-gray-400"><i class="fa-solid fa-image text-4xl"></i></div>
                        <?php endif; ?>
                        <div class="absolute top-3 left-3 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-500 text-white text-[10px] font-bold uppercase tracking-wider">
                            <i class="fa-solid fa-trophy text-[9px]"></i> Won
                        </div>
                    </div>
                    <div class="p-5">
                        <h3 class="font-bold leading-snug line-clamp-2"><?= htmlspecialchars($win['title']) ?></h3>
                        <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800 flex justify-between gap-3">
                            <div>
                                <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Winning Bid</div>
                                <div class="text-lg font-black tabular-nums">Rs. <?= number_format((float) $win['winning_bid']) ?></div>
                            </div>
                            <div class="text-right">
                                <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Won On</div>
                                <div class="text-xs font-semibold"><?= date('d M Y', strtotime($win['won_at'])) ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <div class="p-5 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                        <span class="w-9 h-9 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-xs"><i class="fa-solid fa-store"></i></span>
                        <div>
                            <h3 class="text-sm font-bold">Seller</h3>
                            <p class="text-[10px] text-gray-500 mt-0.5">Who you're reviewing</p>
                        </div>
                    </div>
                    <div class="p-5">
                        <div class="flex items-center gap-3">
                            <?php if (!empty($win['seller_avatar'])): ?>
                                    <img src="../uploads/avatars/<?= htmlspecialchars($win['seller_avatar']) ?>" class="w-12 h-12 rounded-xl object-cover" alt="">
                            <?php else: ?>
                                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-violet-500 to-indigo-500 text-white font-bold text-sm flex items-center justify-center"><?= htmlspecialchars($seller_initials) ?></div>
                            <?php endif; ?>
                            <div class="min-w-0 flex-1">
                                <div class="font-bold truncate"><?= htmlspecialchars($win['seller_name']) ?></div>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <?php if ($seller_review_count > 0): ?>
                                            <div class="flex items-center gap-1"><?= nb_render_stars($seller_avg_rating, 'text-[11px]') ?></div>
                                            <span class="text-[11px] text-gray-500"><?= number_format($seller_avg_rating, 1) ?> · <?= $seller_review_count ?> review<?= $seller_review_count === 1 ? '' : 's' ?></span>
                                    <?php else: ?>
                                            <span class="text-[11px] text-gray-400 italic">No reviews yet</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Form / Reviewed -->
            <div class="lg:col-span-7">
                <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">

                    <?php if ($already_reviewed && $existing_review): ?>
                            <div class="p-8 text-center">
                                <div class="w-20 h-20 mx-auto rounded-full bg-gradient-to-br from-emerald-500 to-teal-500 flex items-center justify-center text-white text-3xl shadow-lg mb-5">
                                    <i class="fa-solid fa-circle-check"></i>
                                </div>
                                <h2 class="text-2xl font-black mb-2">Review Submitted</h2>
                                <p class="text-sm text-gray-500 dark:text-gray-400 max-w-md mx-auto">Thank you for your feedback! Your review is now visible to other buyers.</p>

                                <div class="mt-8 max-w-md mx-auto text-left">
                                    <div class="p-5 rounded-2xl bg-gray-50 dark:bg-slate-800/50 border border-gray-200 dark:border-gray-800">
                                        <div class="flex items-center gap-1 mb-3"><?= nb_render_stars((int) $existing_review['rating'], 'text-xl') ?></div>
                                        <?php if (!empty($existing_review['review_text'])): ?>
                                                <p class="text-sm text-gray-700 dark:text-gray-300 italic leading-relaxed">"<?= htmlspecialchars($existing_review['review_text']) ?>"</p>
                                        <?php else: ?>
                                                <p class="text-sm text-gray-400 italic">No written comment provided.</p>
                                        <?php endif; ?>
                                        <?php if (!empty($existing_review['created_at'])): ?>
                                                <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 text-[11px] text-gray-400">Submitted <?= time_ago_short($existing_review['created_at']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-3 justify-center mt-8">
                                    <a href="orders.php" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold transition">
                                        <i class="fa-solid fa-arrow-left text-xs"></i> Back to Orders
                                    </a>
                                    <a href="../auction-details.php?id=<?= $auction_id ?>" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 text-gray-700 dark:text-gray-200 text-sm font-bold transition">
                                        <i class="fa-regular fa-eye text-xs"></i> View Auction
                                    </a>
                                </div>
                            </div>
                    <?php else: ?>
                            <div class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                <span class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-sm"><i class="fa-solid fa-pen"></i></span>
                                <div>
                                    <h2 class="text-base font-bold">Write Your Review</h2>
                                    <p class="text-xs text-gray-500 mt-0.5">Share your experience with this seller</p>
                                </div>
                            </div>

                            <form method="POST" class="p-5 sm:p-6 space-y-6">
                                <?php nb_csrf_field(); ?>
                                <input type="hidden" name="auction_id" value="<?= $auction_id ?>">

                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider mb-3">Your Rating <span class="text-rose-500">*</span></label>
                                    <div class="p-5 rounded-2xl bg-gradient-to-br from-amber-500/5 to-orange-500/5 border border-amber-500/20 text-center">
                                        <div class="star-rating justify-center mb-3" id="starRating">
                                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                                    <input type="radio" id="star<?= $i ?>" name="rating" value="<?= $i ?>" required>
                                                    <label for="star<?= $i ?>" title="<?= $i ?> star<?= $i === 1 ? '' : 's' ?>"><i class="fa-solid fa-star"></i></label>
                                            <?php endfor; ?>
                                        </div>
                                        <p id="ratingLabel" class="text-sm font-bold text-gray-500 transition-colors">Select a rating</p>
                                    </div>
                                </div>

                                <div>
                                    <label for="comment" class="block text-xs font-bold uppercase tracking-wider mb-2">Your Feedback <span class="text-gray-400 font-normal normal-case">(optional)</span></label>
                                    <div class="relative">
                                        <textarea name="comment" id="comment" rows="5" maxlength="1000" placeholder="Share details about your experience — communication, shipping speed, item condition..." class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"></textarea>
                                        <div class="absolute bottom-3 right-3 text-[10px] text-gray-400 tabular-nums"><span id="commentCount">0</span>/1000</div>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider mb-2.5">Quick Tags</label>
                                    <div class="flex flex-wrap gap-2" id="tagContainer">
                                        <?php foreach (['Great communication', 'Fast shipping', 'Item as described', 'Well packaged', 'Fair pricing', 'Would buy again'] as $tag): ?>
                                                <button type="button" data-tag="<?= htmlspecialchars($tag) ?>" class="tag-chip px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-slate-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:border-indigo-500 text-xs font-semibold transition"><?= htmlspecialchars($tag) ?></button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="pt-2 flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                                    <a href="orders.php" class="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-gray-100 dark:bg-slate-800 text-gray-700 dark:text-gray-200 text-sm font-semibold transition">
                                        <i class="fa-solid fa-xmark text-xs"></i> Cancel
                                    </a>
                                    <button type="submit" class="group flex-1 inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-400 hover:to-orange-400 text-white text-sm font-bold shadow-lg shadow-amber-500/30 transition hover:-translate-y-0.5">
                                        <i class="fa-solid fa-paper-plane text-xs"></i> Submit Review
                                        <i class="fa-solid fa-arrow-right text-xs group-hover:translate-x-0.5 transition-transform"></i>
                                    </button>
                                </div>
                            </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        (function () {
            const ratingLabels = {1:'Poor — Very disappointing',2:'Fair — Below expectations',3:'Good — Met expectations',4:'Very Good — Exceeded expectations',5:'Excellent — Outstanding experience'};
            const ratingColors = {1:'text-rose-500',2:'text-orange-500',3:'text-amber-500',4:'text-lime-500',5:'text-emerald-500'};
            const ratingLabel = document.getElementById('ratingLabel');
            document.querySelectorAll('input[name="rating"]').forEach(input => {
                input.addEventListener('change', function () {
                    if (!ratingLabel) return;
                    const v = parseInt(this.value, 10);
                    ratingLabel.textContent = ratingLabels[v] || '';
                    ratingLabel.className = 'text-sm font-bold transition-colors ' + (ratingColors[v] || 'text-gray-500');
                });
            });
            const commentInput = document.getElementById('comment');
            const commentCount = document.getElementById('commentCount');
            commentInput?.addEventListener('input', function () { if (commentCount) commentCount.textContent = this.value.length; });
            document.querySelectorAll('.tag-chip').forEach(chip => {
                chip.addEventListener('click', function () {
                    if (!commentInput) return;
                    const tag = this.dataset.tag;
                    const current = commentInput.value.trim();
                    const sep = current ? ' • ' : '';
                    if (!current.includes(tag)) {
                        commentInput.value = (current + sep + tag).trim();
                        if (commentCount) commentCount.textContent = commentInput.value.length;
                    }
                });
            });
            const btn = document.getElementById('themeToggle');
            btn?.addEventListener('click', () => {
                const isDark = document.documentElement.classList.toggle('dark');
                try { localStorage.setItem('theme', isDark ? 'dark' : 'light'); } catch(e){}
            });
        })();
    </script>
    <?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>
</html>