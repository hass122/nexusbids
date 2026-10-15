<?php
/* =========================================================
   Seller Sidebar — Safe Include (prevents redeclare errors)
   ========================================================= */

// Guard: prevent duplicate loading
if (defined('SELLER_SIDEBAR_LOADED')) {
    return;
}
define('SELLER_SIDEBAR_LOADED', true);

$sb_name = $full_name ?? ($_SESSION['full_name'] ?? 'Seller');
$sb_avatar = $avatar ?? '';
$sb_init = $initials ?? strtoupper(substr(preg_replace('/\s+/', '', $sb_name), 0, 2));
$sb_unread = $unread_notifications ?? 0;
$sb_total = $sidebar_total ?? 0;
$sb_active = $sidebar_active ?? 0;
$sb_pending = $sidebar_pending ?? 0;
$sb_drafts = $sidebar_drafts ?? 0;
$sb_page = $sidebar_active_page ?? '';
$sb_current = basename($_SERVER['PHP_SELF']);

// Guarded function declaration
if (!function_exists('sb_link_class')) {
    function sb_link_class($key, $sb_page, $sb_current, $match = null)
    {
        $is_active = ($sb_page === $key);
        if (!$is_active && $match && in_array($sb_current, (array) $match, true)) {
            $is_active = true;
        }
        return $is_active
            ? 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 font-semibold'
            : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-800';
    }
}
?>
<!-- Overlay -->
<div id="sidebarOverlay"
    onclick="document.getElementById('sellerSidebar').classList.add('-translate-x-full'); this.classList.add('hidden');"
    class="hidden fixed inset-0 bg-black/50 z-40 lg:hidden"></div>

<aside id="sellerSidebar"
    class="fixed top-0 left-0 bottom-0 w-64 z-50 bg-white dark:bg-slate-900 border-r border-gray-200 dark:border-gray-800 flex flex-col -translate-x-full lg:translate-x-0 transition-transform duration-300">

    <!-- Logo -->
    <div class="h-20 px-5 flex items-center gap-3 border-b border-gray-100 dark:border-gray-800">
        <div
            class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-600 to-violet-600 text-white flex items-center justify-center font-black shadow-lg">
            NB
        </div>
        <div class="min-w-0">
            <div class="text-sm font-black tracking-tight">NexusBids</div>
            <div class="text-[10px] text-gray-500 dark:text-gray-400 uppercase tracking-wider font-bold">Seller Panel
            </div>
        </div>
    </div>

    <!-- User card -->
    <div class="p-4 border-b border-gray-100 dark:border-gray-800">
        <div class="flex items-center gap-3 p-3 rounded-2xl bg-gray-50 dark:bg-slate-800/50">
            <?php if (!empty($sb_avatar)): ?>
                <img src="../uploads/avatars/<?= htmlspecialchars($sb_avatar) ?>" class="w-10 h-10 rounded-xl object-cover"
                    alt="">
            <?php else: ?>
                <div
                    class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center">
                    <?= htmlspecialchars($sb_init) ?>
                </div>
            <?php endif; ?>
            <div class="min-w-0 flex-1">
                <div class="text-xs font-bold truncate"><?= htmlspecialchars($sb_name) ?></div>
                <a href="notifications.php"
                    class="text-[10px] text-gray-500 dark:text-gray-400 hover:text-indigo-600 flex items-center gap-1">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($sb_unread > 0): ?>
                        <span
                            class="px-1.5 rounded-full bg-rose-500 text-white text-[9px] font-bold"><?= $sb_unread ?></span>
                    <?php else: ?>
                        Notifications
                    <?php endif; ?>
                </a>
            </div>
        </div>
    </div>

    <!-- Nav -->
    <nav class="flex-1 overflow-y-auto p-3 space-y-1">

        <a href="dashboard.php"
            class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm transition-all <?= sb_link_class('dashboard', $sb_page, $sb_current) ?>">
            <i class="fa-solid fa-chart-line w-4 text-center"></i> Dashboard
        </a>

        <div class="pt-3 pb-1 px-3">
            <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Auctions</div>
        </div>

        <a href="create-auction.php"
            class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm transition-all <?= sb_link_class('create', $sb_page, $sb_current) ?>">
            <i class="fa-solid fa-plus w-4 text-center"></i> Create Auction
        </a>

        <a href="my-auctions.php"
            class="flex items-center justify-between gap-3 px-3 py-2.5 rounded-xl text-sm transition-all <?= sb_link_class('my-auctions', $sb_page, $sb_current) ?>">
            <span class="flex items-center gap-3"><i class="fa-solid fa-box w-4 text-center"></i> My Auctions</span>
            <span
                class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 dark:bg-slate-800 font-bold tabular-nums"><?= (int) $sb_total ?></span>
        </a>

        <a href="bid-activity.php"
            class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm transition-all <?= sb_link_class('bid-activity', $sb_page, $sb_current) ?>">
            <i class="fa-solid fa-gavel w-4 text-center"></i> Bid Activity
        </a>

        <div class="pt-3 pb-1 px-3">
            <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Sales</div>
        </div>

        <a href="orders.php"
            class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm transition-all <?= sb_link_class('orders', $sb_page, $sb_current, ['order-details.php', 'ship-order.php']) ?>">
            <i class="fa-solid fa-receipt w-4 text-center"></i> Orders
        </a>

        <a href="reviews.php"
            class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm transition-all <?= sb_link_class('reviews', $sb_page, $sb_current) ?>">
            <i class="fa-solid fa-star w-4 text-center"></i> Reviews
        </a>

        <div class="pt-3 pb-1 px-3">
            <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Money</div>
        </div>

        <a href="earnings.php"
            class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm transition-all <?= sb_link_class('earnings', $sb_page, $sb_current) ?>">
            <i class="fa-solid fa-coins w-4 text-center"></i> Earnings
        </a>

        <a href="escrow.php"
            class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm transition-all <?= sb_link_class('escrow', $sb_page, $sb_current) ?>">
            <i class="fa-solid fa-lock w-4 text-center"></i> Escrow Holds
        </a>

        <a href="transactions.php"
            class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm transition-all <?= sb_link_class('transactions', $sb_page, $sb_current) ?>">
            <i class="fa-solid fa-credit-card w-4 text-center"></i> Transactions
        </a>

        <a href="payouts.php"
            class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm transition-all <?= sb_link_class('payouts', $sb_page, $sb_current) ?>">
            <i class="fa-solid fa-wallet w-4 text-center"></i> Payouts
        </a>

        <a href="disputes.php"
            class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm transition-all <?= sb_link_class('disputes', $sb_page, $sb_current) ?>">
            <i class="fa-solid fa-scale-balanced w-4 text-center"></i> Disputes
        </a>

        <div class="pt-3 pb-1 px-3">
            <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Account</div>
        </div>

        <a href="profile.php"
            class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm transition-all <?= sb_link_class('profile', $sb_page, $sb_current) ?>">
            <i class="fa-solid fa-user w-4 text-center"></i> Profile
        </a>

        <a href="security.php"
            class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm transition-all <?= sb_link_class('security', $sb_page, $sb_current) ?>">
            <i class="fa-solid fa-shield-halved w-4 text-center"></i> Security
        </a>

        <a href="../auth/logout.php"
            class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm text-rose-600 dark:text-rose-400 hover:bg-rose-500/10 transition-all">
            <i class="fa-solid fa-right-from-bracket w-4 text-center"></i> Logout
        </a>
    </nav>

    <div
        class="p-4 border-t border-gray-100 dark:border-gray-800 text-[10px] text-gray-400 flex items-center justify-between">
        <span>© <?= date('Y') ?> NexusBids</span>
        <span class="flex items-center gap-1"><span
                class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>Online</span>
    </div>
</aside>