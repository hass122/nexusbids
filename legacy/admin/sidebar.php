<?php
// Sidebar needs: $admin_name, $admin_initials, $unread_notifications,
// $total_users, $total_auctions, $total_bids, $total_winners,
// $total_payments, $pending_payments

$sb_current = basename($_SERVER['PHP_SELF']);
function sb_active($page, $current, $match = []) {
    if ($page === $current) return ' active';
    if (in_array($current, (array)$match, true)) return ' active';
    return '';
}
$sb_admin_name = $admin_name ?? ($_SESSION['full_name'] ?? 'Admin');
$sb_admin_init = $admin_initials ?? 'AD';
$sb_unread     = $unread_notifications ?? 0;
?>
<!-- SIDEBAR -->
<aside class="hidden lg:flex flex-col w-64 shrink-0 bg-white dark:bg-slate-900 border-r border-gray-200 dark:border-gray-800 fixed top-0 bottom-0 left-0 z-40">
    <!-- Logo -->
    <div class="h-20 flex items-center px-6 border-b border-gray-100 dark:border-gray-800">
        <a class="flex items-center gap-2.5 text-xl font-black tracking-tight text-gray-900 dark:text-white group" href="../index.php">
            <span class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30 group-hover:shadow-indigo-500/50 group-hover:scale-105 transition-all duration-300">
                <i class="fa-solid fa-gavel text-lg"></i>
            </span>
            Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
        </a>
    </div>

    <!-- Admin Card -->
    <div class="p-4">
        <div class="flex items-center gap-3 p-3 rounded-2xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 border border-indigo-500/20">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center shadow-lg shadow-indigo-500/30 shrink-0">
                <?= htmlspecialchars($sb_admin_init) ?>
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-xs font-bold text-gray-900 dark:text-white truncate">
                    <?= htmlspecialchars($sb_admin_name) ?>
                </div>
                <div class="text-[10px] text-indigo-600 dark:text-indigo-400 font-semibold flex items-center gap-1 mt-0.5">
                    <i class="fa-solid fa-shield-halved text-[8px]"></i>
                    Administrator
                </div>
            </div>
        </div>
    </div>

    <!-- Nav -->
    <nav class="flex-1 overflow-y-auto px-3 py-2 space-y-1">
        <div class="px-3 py-2 text-[10px] font-bold uppercase tracking-wider text-gray-400">Overview</div>
        <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-800 hover:text-indigo-600 dark:hover:text-indigo-400<?= sb_active('index.php', $sb_current) ?>"
            href="index.php">
            <i class="fa-solid fa-chart-line w-5 text-center"></i> Dashboard
        </a>

        <div class="px-3 py-2 pt-4 text-[10px] font-bold uppercase tracking-wider text-gray-400">Management</div>
        <a class="sidebar-link flex items-center justify-between gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-800 hover:text-indigo-600 dark:hover:text-indigo-400<?= sb_active('users.php', $sb_current) ?>"
            href="users.php">
            <span class="flex items-center gap-3"><i class="fa-solid fa-users w-5 text-center"></i> Users</span>
            <span class="text-[10px] font-bold text-gray-400 tabular-nums"><?= number_format($total_users ?? 0) ?></span>
        </a>
        <a class="sidebar-link flex items-center justify-between gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-800 hover:text-indigo-600 dark:hover:text-indigo-400<?= sb_active('auctions.php', $sb_current, ['auction-action.php']) ?>"
            href="auctions.php">
            <span class="flex items-center gap-3"><i class="fa-solid fa-box w-5 text-center"></i> Auctions</span>
            <span class="text-[10px] font-bold text-gray-400 tabular-nums"><?= number_format($total_auctions ?? 0) ?></span>
        </a>
        <a class="sidebar-link flex items-center justify-between gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-800 hover:text-indigo-600 dark:hover:text-indigo-400<?= sb_active('bids.php', $sb_current) ?>"
            href="bids.php">
            <span class="flex items-center gap-3"><i class="fa-solid fa-gavel w-5 text-center"></i> Bids</span>
            <span class="text-[10px] font-bold text-gray-400 tabular-nums"><?= number_format($total_bids ?? 0) ?></span>
        </a>
        <a class="sidebar-link flex items-center justify-between gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-800 hover:text-indigo-600 dark:hover:text-indigo-400<?= sb_active('winners.php', $sb_current, ['send-reminder.php']) ?>"
            href="winners.php">
            <span class="flex items-center gap-3"><i class="fa-solid fa-trophy w-5 text-center"></i> Winners</span>
            <span class="text-[10px] font-bold text-gray-400 tabular-nums"><?= number_format($total_winners ?? 0) ?></span>
        </a>
        <a class="sidebar-link flex items-center justify-between gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-800 hover:text-indigo-600 dark:hover:text-indigo-400<?= sb_active('orders.php', $sb_current, ['order-details.php','verify-payment.php']) ?>"
            href="orders.php">
            <span class="flex items-center gap-3"><i class="fa-solid fa-receipt w-5 text-center"></i> Orders</span>
        </a>
        <a class="sidebar-link flex items-center justify-between gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-800 hover:text-indigo-600 dark:hover:text-indigo-400<?= sb_active('payments.php', $sb_current) ?>"
            href="payments.php">
            <span class="flex items-center gap-3"><i class="fa-solid fa-credit-card w-5 text-center"></i> Payments</span>
            <?php if (($pending_payments ?? 0) > 0): ?>
                <span class="text-[10px] font-black text-white bg-amber-500 px-2 py-0.5 rounded-full tabular-nums"><?= $pending_payments ?></span>
            <?php else: ?>
                <span class="text-[10px] font-bold text-gray-400 tabular-nums"><?= number_format($total_payments ?? 0) ?></span>
            <?php endif; ?>
        </a>
        <a class="sidebar-link flex items-center justify-between gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-800 hover:text-indigo-600 dark:hover:text-indigo-400<?= sb_active('categories.php', $sb_current, ['add-category.php','edit-category.php']) ?>"
            href="categories.php">
            <span class="flex items-center gap-3"><i class="fa-solid fa-layer-group w-5 text-center"></i> Categories</span>
        </a>

        <!-- FINANCE SECTION -->
        <div class="px-3 py-2 pt-4 text-[10px] font-bold uppercase tracking-wider text-gray-400">Finance</div>
        <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-800 hover:text-indigo-600 dark:hover:text-indigo-400<?= sb_active('payouts.php', $sb_current) ?>"
            href="payouts.php">
            <i class="fa-solid fa-wallet w-5 text-center"></i> Payout Approvals
        </a>
        <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-800 hover:text-indigo-600 dark:hover:text-indigo-400<?= sb_active('escrow.php', $sb_current) ?>"
            href="escrow.php">
            <i class="fa-solid fa-lock w-5 text-center"></i> Escrow Holds
        </a>
        <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-800 hover:text-indigo-600 dark:hover:text-indigo-400<?= sb_active('transactions.php', $sb_current) ?>"
            href="transactions.php">
            <i class="fa-solid fa-money-bill-transfer w-5 text-center"></i> Transactions
        </a>
        <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-800 hover:text-indigo-600 dark:hover:text-indigo-400<?= sb_active('ledger.php', $sb_current) ?>"
            href="ledger.php">
            <i class="fa-solid fa-book w-5 text-center"></i> Seller Ledger
        </a>
        <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-800 hover:text-indigo-600 dark:hover:text-indigo-400<?= sb_active('disputes.php', $sb_current) ?>"
            href="disputes.php">
            <i class="fa-solid fa-scale-balanced w-5 text-center"></i> Disputes
        </a>
        <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-800 hover:text-indigo-600 dark:hover:text-indigo-400<?= sb_active('refunds.php', $sb_current) ?>"
            href="refunds.php">
            <i class="fa-solid fa-rotate-left w-5 text-center"></i> Refunds
        </a>

        <!-- SYSTEM SECTION -->
        <div class="px-3 py-2 pt-4 text-[10px] font-bold uppercase tracking-wider text-gray-400">System</div>
        <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-800 hover:text-indigo-600 dark:hover:text-indigo-400<?= sb_active('activity-log.php', $sb_current) ?>"
            href="activity-log.php">
            <i class="fa-solid fa-clock-rotate-left w-5 text-center"></i> Activity Log
        </a>
        <a class="sidebar-link flex items-center justify-between gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-800 hover:text-indigo-600 dark:hover:text-indigo-400"
            href="../notifications.php">
            <span class="flex items-center gap-3"><i class="fa-solid fa-bell w-5 text-center"></i> Notifications</span>
            <?php if ($sb_unread > 0): ?>
                <span class="text-[10px] font-black text-white bg-rose-500 px-2 py-0.5 rounded-full tabular-nums"><?= $sb_unread ?></span>
            <?php endif; ?>
        </a>
        <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-800 hover:text-indigo-600 dark:hover:text-indigo-400"
            href="../index.php">
            <i class="fa-solid fa-globe w-5 text-center"></i> View Site
        </a>
    </nav>

    <!-- Logout -->
    <div class="p-3 border-t border-gray-100 dark:border-gray-800">
        <a class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition-colors duration-200"
            href="../auth/logout.php">
            <i class="fa-solid fa-right-from-bracket w-5 text-center"></i> Logout
        </a>
    </div>
</aside>