<?php
session_start();
require_once "../config/database.php";
require_once "../includes/review-helpers.php";
require_once "../includes/notifications.php";

date_default_timezone_set('Asia/Karachi');

$seller_id = intval($_GET["id"] ?? 0);

if ($seller_id <= 0) {
    header("Location: ../index.php");
    exit();
}

/* Seller info */
$s_stmt = $conn->prepare("SELECT user_id, full_name, email, avatar, created_at FROM users WHERE user_id = ? AND role = 'seller' LIMIT 1");
$s_stmt->bind_param("i", $seller_id);
$s_stmt->execute();
$seller = $s_stmt->get_result()->fetch_assoc();
$s_stmt->close();

if (!$seller) {
    die("Seller not found.");
}

$seller_initials = strtoupper(substr(preg_replace('/\s+/', '', $seller['full_name']), 0, 2));

/* Ratings */
$rating = nb_seller_rating($conn, $seller_id);
$reviews = nb_reviews_for_seller($conn, $seller_id, 50);

/* Distribution */
$dist_stmt = $conn->prepare("SELECT rating, COUNT(*) AS total FROM reviews WHERE reviewee_id = ? GROUP BY rating");
$dist_stmt->bind_param("i", $seller_id);
$dist_stmt->execute();
$dist_result = $dist_stmt->get_result();
$distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
while ($row = $dist_result->fetch_assoc()) {
    $distribution[intval($row['rating'])] = intval($row['total']);
}
$dist_stmt->close();

/* Active listings */
$listings_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM auctions WHERE seller_id = ? AND auction_status = 'active' AND end_time > NOW()");
$listings_stmt->bind_param("i", $seller_id);
$listings_stmt->execute();
$active_listings = intval($listings_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$listings_stmt->close();

/* Recent active */
$recent_stmt = $conn->prepare("SELECT auction_id, title, image, current_bid, starting_price, end_time FROM auctions WHERE seller_id = ? AND auction_status = 'active' AND end_time > NOW() ORDER BY end_time ASC LIMIT 4");
$recent_stmt->bind_param("i", $seller_id);
$recent_stmt->execute();
$recent_auctions = $recent_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$recent_stmt->close();

/* Navbar user context */
$is_logged = !empty($_SESSION['user_id']);
$nav_name = 'Guest';
$nav_initials = 'GU';
$nav_avatar = '';
$unread_notifications = 0;
if ($is_logged) {
    $uid = (int) $_SESSION['user_id'];
    $u_stmt = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ?");
    $u_stmt->bind_param("i", $uid);
    $u_stmt->execute();
    $uinfo = $u_stmt->get_result()->fetch_assoc();
    $u_stmt->close();
    if ($uinfo) {
        $nav_name = $uinfo['full_name'] ?? 'User';
        $nav_avatar = $uinfo['avatar'] ?? '';
        $nav_initials = strtoupper(substr(preg_replace('/\s+/', '', $nav_name), 0, 2));
    }
    if (function_exists('nb_unread_count')) {
        try {
            $unread_notifications = intval(nb_unread_count($conn, $uid));
        } catch (Throwable $e) {
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
    <title><?= htmlspecialchars($seller['full_name']) ?> · Seller Profile · NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = { darkMode: 'class', theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] }, animation: { 'pulse-soft': 'pulseSoft 2s ease-in-out infinite' }, keyframes: { pulseSoft: { '0%, 100%': { opacity: '1' }, '50%': { opacity: '0.5' } } } } } };
        (function () { try { const s = localStorage.getItem('theme'); const d = window.matchMedia('(prefers-color-scheme: dark)').matches; if (s === 'dark' || (!s && d)) document.documentElement.classList.add('dark'); } catch (e) { } })();
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
        }

        .glass {
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
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

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                animation-duration: .01ms !important;
                transition-duration: .01ms !important;
            }
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 font-sans antialiased min-h-screen">

    <!-- Top Utility Bar -->
    <div class="bg-gray-950 text-gray-400 text-xs py-2.5 px-4 sm:px-8 border-b border-gray-800/50">
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-2">
            <div class="flex items-center space-x-6">
                <span class="flex items-center gap-2"><i class="fa-solid fa-location-dot text-indigo-400"></i>
                    Pakistan</span>
                <span class="hidden sm:flex items-center gap-2"><i class="fa-solid fa-phone text-indigo-400"></i> +92
                    300 1234567</span>
            </div>
            <div class="flex items-center space-x-6">
                <a href="../about.php" class="hover:text-white"><i
                        class="fa-regular fa-circle-question text-indigo-400"></i> Help Center</a>
                <a href="../about.php#why-nexusbids" class="hover:text-white"><i
                        class="fa-solid fa-shield-halved text-indigo-400"></i> Secure Bidding</a>
            </div>
        </div>
    </div>

    <!-- Navbar -->
    <header
        class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
            <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight shrink-0 group" href="../index.php">
                <span
                    class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg group-hover:scale-105 transition-all"><i
                        class="fa-solid fa-gavel text-lg"></i></span>
                Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
            </a>
            <nav class="hidden lg:flex items-center gap-7 text-sm font-medium">
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition"
                    href="../index.php">Home</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition"
                    href="../auctions.php">Auctions</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition"
                    href="../categories.php">Categories</a>
                <?php if ($is_logged): ?>
                    <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition"
                        href="dashboard.php">Dashboard</a>
                <?php endif; ?>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition"
                    href="../about.php">About</a>
            </nav>
            <div class="flex items-center gap-2 sm:gap-3">
                <button id="themeToggle"
                    class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition">
                    <i class="fa-solid fa-moon dark:hidden"></i><i
                        class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                </button>
                <?php if ($is_logged): ?>
                    <a href="../notifications.php"
                        class="relative w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition">
                        <i class="fa-regular fa-bell"></i>
                        <?php if ($unread_notifications > 0): ?><span
                                class="absolute top-1.5 right-1.5 w-2.5 h-2.5 rounded-full bg-rose-500 border-2 border-white dark:border-gray-800 animate-pulse-soft"></span><?php endif; ?>
                    </a>
                    <div class="flex items-center gap-3 pl-3 border-l border-gray-200 dark:border-gray-800">
                        <div class="relative">
                            <?php if (!empty($nav_avatar)): ?>
                                <img src="../uploads/avatars/<?= htmlspecialchars($nav_avatar) ?>"
                                    class="w-10 h-10 rounded-full object-cover ring-2 ring-white dark:ring-slate-900" alt="">
                            <?php else: ?>
                                <div
                                    class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center shadow-lg ring-2 ring-white dark:ring-slate-900">
                                    <?= htmlspecialchars($nav_initials) ?></div>
                            <?php endif; ?>
                            <span
                                class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-emerald-500 border-2 border-white dark:border-slate-900"></span>
                        </div>
                        <div class="hidden sm:block text-left">
                            <div class="text-sm font-bold leading-tight truncate max-w-32">
                                <?= htmlspecialchars($nav_name) ?></div>
                            <div
                                class="text-[11px] text-emerald-600 dark:text-emerald-400 font-medium flex items-center gap-1">
                                <i class="fa-solid fa-circle-check text-[8px]"></i> Verified
                            </div>
                        </div>
                    </div>
                    <a class="hidden sm:inline-flex px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition"
                        href="../auth/logout.php">
                        <i class="fa-solid fa-right-from-bracket mr-1.5"></i> Logout
                    </a>
                <?php else: ?>
                    <a class="hidden sm:inline-flex px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition"
                        href="../auth/login.php">
                        <i class="fa-solid fa-right-to-bracket mr-1.5"></i> Login
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-4 sm:px-8 py-8 space-y-6">

        <!-- Seller header -->
        <div
            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
            <div class="h-32 bg-gradient-to-r from-violet-600 via-indigo-600 to-blue-600 relative">
                <div class="absolute inset-0 opacity-30"
                    style="background-image: linear-gradient(rgba(255,255,255,.15) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.15) 1px, transparent 1px); background-size: 30px 30px;">
                </div>
            </div>
            <div class="px-6 pb-6 -mt-16 relative">
                <?php if (!empty($seller['avatar'])): ?>
                    <img src="../uploads/avatars/<?= htmlspecialchars($seller['avatar']) ?>"
                        class="w-28 h-28 rounded-2xl object-cover ring-4 ring-white dark:ring-slate-900 shadow-xl" alt="">
                <?php else: ?>
                    <div
                        class="w-28 h-28 rounded-2xl bg-gradient-to-br from-violet-500 to-indigo-500 text-white font-black text-3xl flex items-center justify-center ring-4 ring-white dark:ring-slate-900 shadow-xl">
                        <?= htmlspecialchars($seller_initials) ?></div>
                <?php endif; ?>

                <div class="mt-4 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-black"><?= htmlspecialchars($seller['full_name']) ?></h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Member since
                            <?= date('M Y', strtotime($seller['created_at'])) ?></p>
                        <div class="flex items-center gap-2 mt-2">
                            <span
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20 text-[10px] font-bold uppercase tracking-wider">
                                <i class="fa-solid fa-circle-check text-[9px]"></i> Verified Seller
                            </span>
                        </div>
                    </div>
                    <div class="text-right">
                        <?php if ($rating['count'] > 0): ?>
                            <div class="text-3xl font-black tabular-nums"><?= number_format($rating['avg'], 1) ?></div>
                            <div class="flex items-center justify-end gap-0.5 mt-1">
                                <?= nb_render_stars($rating['avg'], 'text-sm') ?></div>
                            <p class="text-[11px] text-gray-500 mt-1"><?= $rating['count'] ?>
                                review<?= $rating['count'] === 1 ? '' : 's' ?></p>
                        <?php else: ?>
                            <p class="text-sm text-gray-400 italic">No reviews yet</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                <div class="flex items-center gap-3">
                    <div
                        class="w-11 h-11 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                        <i class="fa-solid fa-bolt"></i></div>
                    <div>
                        <div class="text-xl font-black tabular-nums"><?= $active_listings ?></div>
                        <p class="text-[11px] text-gray-500">Active Listings</p>
                    </div>
                </div>
            </div>
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                <div class="flex items-center gap-3">
                    <div
                        class="w-11 h-11 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                        <i class="fa-solid fa-star"></i></div>
                    <div>
                        <div class="text-xl font-black tabular-nums">
                            <?= $rating['count'] > 0 ? number_format($rating['avg'], 1) : '—' ?></div>
                        <p class="text-[11px] text-gray-500">Avg Rating</p>
                    </div>
                </div>
            </div>
            <div
                class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 col-span-2 sm:col-span-1">
                <div class="flex items-center gap-3">
                    <div
                        class="w-11 h-11 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center">
                        <i class="fa-solid fa-comment-dots"></i></div>
                    <div>
                        <div class="text-xl font-black tabular-nums"><?= $rating['count'] ?></div>
                        <p class="text-[11px] text-gray-500">Total Reviews</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Listings -->
        <?php if (!empty($recent_auctions)): ?>
            <div
                class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                <div class="p-5 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                    <h2 class="text-base font-bold flex items-center gap-2"><i class="fa-solid fa-fire text-orange-500"></i>
                        Active Listings</h2>
                    <a href="../auctions.php?seller=<?= $seller_id ?>"
                        class="text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">View All →</a>
                </div>
                <div class="p-5 grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <?php foreach ($recent_auctions as $a):
                        $price = floatval($a['current_bid']) > 0 ? floatval($a['current_bid']) : floatval($a['starting_price']);
                        ?>
                        <a href="../auction-details.php?id=<?= (int) $a['auction_id'] ?>"
                            class="group rounded-xl border border-gray-200 dark:border-gray-800 overflow-hidden hover:border-indigo-500 transition">
                            <div class="h-24 bg-gray-100 dark:bg-slate-800 overflow-hidden">
                                <?php if (!empty($a['image'])): ?>
                                    <img src="../uploads/auctions/<?= htmlspecialchars($a['image']) ?>"
                                        class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500"
                                        alt="">
                                <?php else: ?>
                                    <div class="w-full h-full flex items-center justify-center text-gray-400"><i
                                            class="fa-solid fa-image"></i></div>
                                <?php endif; ?>
                            </div>
                            <div class="p-3">
                                <h4 class="text-xs font-bold line-clamp-2 min-h-8 leading-snug">
                                    <?= htmlspecialchars($a['title']) ?></h4>
                                <div class="text-[11px] font-black text-indigo-600 dark:text-indigo-400 mt-1.5 tabular-nums">Rs.
                                    <?= number_format($price) ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Reviews -->
        <div
            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
            <div class="p-5 border-b border-gray-100 dark:border-gray-800">
                <h2 class="text-base font-bold flex items-center gap-2"><i class="fa-solid fa-star text-amber-500"></i>
                    Reviews <span class="text-xs font-medium text-gray-400">(<?= count($reviews) ?>)</span></h2>
            </div>

            <?php if (!empty($reviews)): ?>
                <div class="p-5 border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-slate-800/30">
                    <div class="space-y-2 max-w-md">
                        <?php for ($i = 5; $i >= 1; $i--):
                            $count = $distribution[$i] ?? 0;
                            $pct = $rating['count'] > 0 ? round(($count / $rating['count']) * 100) : 0;
                            ?>
                            <div class="flex items-center gap-3 text-xs">
                                <span class="w-8 font-bold"><?= $i ?>★</span>
                                <div class="flex-1 h-2 rounded-full bg-gray-200 dark:bg-slate-700 overflow-hidden">
                                    <div class="h-full bg-amber-500 rounded-full transition-all duration-500"
                                        style="width: <?= $pct ?>%"></div>
                                </div>
                                <span class="w-10 text-right text-gray-500 tabular-nums"><?= $count ?></span>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    <?php foreach ($reviews as $r):
                        $initials = strtoupper(substr(preg_replace('/\s+/', '', $r['reviewer_name']), 0, 2));
                        ?>
                        <div class="p-5 hover:bg-gray-50 dark:hover:bg-slate-800/30 transition">
                            <div class="flex items-start gap-4">
                                <div
                                    class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center shrink-0">
                                    <?= htmlspecialchars($initials) ?></div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex flex-wrap items-center gap-2 justify-between">
                                        <div>
                                            <div class="font-bold text-sm"><?= htmlspecialchars($r['reviewer_name']) ?></div>
                                            <div class="flex items-center gap-1 mt-0.5">
                                                <?= nb_render_stars($r['rating'], 'text-[11px]') ?></div>
                                        </div>
                                        <span class="text-[11px] text-gray-400"><?= time_ago_short($r['created_at']) ?></span>
                                    </div>
                                    <?php if (!empty($r['review_text'])): ?>
                                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-2 leading-relaxed">
                                            <?= nl2br(htmlspecialchars($r['review_text'])) ?></p>
                                    <?php endif; ?>
                                    <a href="../auction-details.php?id=<?= (int) $r['auction_id'] ?>"
                                        class="text-[11px] text-indigo-600 dark:text-indigo-400 hover:underline mt-2 inline-block">→
                                        <?= htmlspecialchars($r['auction_title']) ?></a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="p-16 text-center">
                    <div
                        class="w-16 h-16 mx-auto rounded-2xl bg-gray-100 dark:bg-slate-800 text-gray-400 flex items-center justify-center text-2xl mb-4">
                        <i class="fa-regular fa-star"></i></div>
                    <h3 class="font-bold">No reviews yet</h3>
                    <p class="text-sm text-gray-500 mt-1">This seller hasn't been reviewed yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        (function () {
            const btn = document.getElementById('themeToggle');
            btn?.addEventListener('click', () => {
                const isDark = document.documentElement.classList.toggle('dark');
                try { localStorage.setItem('theme', isDark ? 'dark' : 'light'); } catch (e) { }
            });
        })();
    </script>
    <?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>