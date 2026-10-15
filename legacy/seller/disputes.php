<?php

session_start();

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "seller") {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";
require_once "../includes/ledger.php";

date_default_timezone_set('Asia/Karachi');

$seller_id = intval($_SESSION["user_id"]);

$s = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ?");
$s->bind_param("i", $seller_id);
$s->execute();
$seller = $s->get_result()->fetch_assoc();
$s->close();

$full_name = $seller["full_name"] ?? "Seller";
$avatar = $seller["avatar"] ?? "";
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));
$unread_notifications = nb_unread_count($conn, $seller_id);

$sidebar_total = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id")->fetch_assoc()["t"] ?? 0;
$sidebar_active = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'active'")->fetch_assoc()["t"] ?? 0;
$sidebar_pending = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'pending'")->fetch_assoc()["t"] ?? 0;
$sidebar_drafts = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'draft'")->fetch_assoc()["t"] ?? 0;

$tables = nb_ledger_tables_exist($conn);
$disputes_ready = $tables['payment_disputes'] ?? false;

$status_f = trim($_GET['status'] ?? '');
$disputes = nb_disputes_list($conn, $seller_id, ['status' => $status_f, 'limit' => 200]);

function nb_date($d, $f = 'd M Y')
{
    if (empty($d))
        return '—';
    $ts = strtotime($d);
    return $ts ? date($f, $ts) : '—';
}

$status_map = [
    'open' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Open', 'dot' => 'bg-rose-500'],
    'under_review' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'label' => 'Under Review', 'dot' => 'bg-amber-500'],
    'evidence_required' => ['bg' => 'bg-orange-500/10', 'text' => 'text-orange-700 dark:text-orange-400', 'label' => 'Evidence Required', 'dot' => 'bg-orange-500'],
    'won' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Won', 'dot' => 'bg-emerald-500'],
    'lost' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Lost', 'dot' => 'bg-rose-500'],
    'cancelled' => ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'label' => 'Cancelled', 'dot' => 'bg-gray-500'],
];
$default = ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'label' => 'Unknown', 'dot' => 'bg-gray-500'];
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disputes & Chargebacks · NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = { darkMode: 'class', theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } } };</script>
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

        <?php $sidebar_active_page = 'disputes';
        include __DIR__ . '/../includes/seller-sidebar.php'; ?>

        <main class="flex-1 lg:ml-64 min-w-0">

            <header
                class="sticky top-0 z-30 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
                <div class="h-20 px-4 sm:px-8 flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3 min-w-0">
                        <button
                            onclick="document.getElementById('sellerSidebar').classList.remove('-translate-x-full'); document.getElementById('sidebarOverlay').classList.remove('hidden')"
                            class="lg:hidden w-10 h-10 rounded-xl bg-gray-100 dark:bg-slate-800 flex items-center justify-center">
                            <i class="fa-solid fa-bars"></i>
                        </button>
                        <div class="min-w-0">
                            <div
                                class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-0.5 flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-violet-500"></span> Seller Panel
                            </div>
                            <h1 class="text-lg sm:text-xl font-black truncate">Disputes & Chargebacks</h1>
                        </div>
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
                            class="w-11 h-11 rounded-2xl bg-gradient-to-br from-rose-500 to-red-500 flex items-center justify-center text-white shadow-lg">
                            <i class="fa-solid fa-scale-balanced text-base"></i>
                        </span>
                        Disputes & Chargebacks
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Track buyer disputes and chargebacks on
                        your orders. Respond with evidence in time.</p>
                </section>

                <?php if (!$disputes_ready): ?>
                    <div class="p-6 rounded-3xl bg-blue-500/10 border border-blue-500/30 text-center">
                        <div
                            class="w-14 h-14 mx-auto rounded-2xl bg-blue-500/20 text-blue-600 dark:text-blue-400 flex items-center justify-center text-xl mb-3">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <h3 class="font-bold">No disputes yet</h3>
                        <p class="text-xs text-gray-600 dark:text-gray-400 mt-2 max-w-md mx-auto">
                            The disputes table hasn't been created. Run <code
                                class="px-1 bg-black/5 dark:bg-white/10 rounded font-mono">includes/payment_schema.sql</code>
                            to enable dispute tracking.
                        </p>
                    </div>
                <?php elseif (empty($disputes)): ?>
                    <div
                        class="p-16 text-center bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800">
                        <div
                            class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-emerald-500/10 to-cyan-500/10 text-emerald-500 flex items-center justify-center text-3xl mb-5">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <h3 class="text-xl font-black">All clear!</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">No open disputes on your account.</p>
                    </div>
                <?php else: ?>

                    <form method="GET"
                        class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-4">
                        <select name="status" onchange="this.form.submit()"
                            class="px-3 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm">
                            <option value="">All Status</option>
                            <?php foreach (['open' => 'Open', 'under_review' => 'Under Review', 'evidence_required' => 'Evidence Required', 'won' => 'Won', 'lost' => 'Lost', 'cancelled' => 'Cancelled'] as $k => $v): ?>
                                <option value="<?= $k ?>" <?= $status_f === $k ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>

                    <div class="space-y-4">
                        <?php foreach ($disputes as $d):
                            $st = strtolower($d['status'] ?? 'open');
                            $style = $status_map[$st] ?? $default;
                            ?>
                            <div
                                class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 sm:p-6">
                                <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4 mb-4">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2 mb-2">
                                            <span
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?= $style['bg'] . ' ' . $style['text'] ?> text-[10px] font-bold uppercase tracking-wider">
                                                <span class="w-1.5 h-1.5 rounded-full <?= $style['dot'] ?>"></span>
                                                <?= $style['label'] ?>
                                            </span>
                                            <span
                                                class="px-2 py-0.5 rounded-md bg-gray-100 dark:bg-slate-800 text-[10px] font-bold uppercase tracking-wider text-gray-600 dark:text-gray-400">
                                                <?= htmlspecialchars(str_replace('_', ' ', $d['dispute_type'] ?? 'other')) ?>
                                            </span>
                                        </div>
                                        <a href="order-details.php?id=<?= (int) $d['order_id'] ?>"
                                            class="font-bold text-sm hover:text-indigo-600 line-clamp-1">
                                            <?= htmlspecialchars($d['auction_title'] ?? 'Order') ?>
                                        </a>
                                        <div class="text-[10px] text-gray-400 font-mono mt-0.5">
                                            <?= htmlspecialchars($d['order_number'] ?? ('#' . $d['order_id'])) ?>
                                            · <?= nb_date($d['created_at'] ?? '') ?>
                                        </div>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Disputed
                                            Amount</div>
                                        <div class="text-2xl font-black text-rose-600 dark:text-rose-400 tabular-nums">Rs.
                                            <?= number_format(floatval($d['amount'] ?? 0)) ?></div>
                                    </div>
                                </div>

                                <?php if (!empty($d['reason'])): ?>
                                    <div
                                        class="p-4 rounded-xl bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-gray-800 mb-3">
                                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1.5">Buyer's
                                            Reason</div>
                                        <p class="text-xs text-gray-700 dark:text-gray-300 leading-relaxed">
                                            <?= nl2br(htmlspecialchars($d['reason'])) ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($d['evidence'])): ?>
                                    <div class="p-4 rounded-xl bg-indigo-500/10 border border-indigo-500/20">
                                        <div
                                            class="text-[10px] font-bold uppercase tracking-wider text-indigo-600 dark:text-indigo-400 mb-1.5">
                                            Evidence / Notes</div>
                                        <p class="text-xs text-gray-700 dark:text-gray-300 leading-relaxed">
                                            <?= nl2br(htmlspecialchars($d['evidence'])) ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($d['resolution_notes'])): ?>
                                    <div class="mt-3 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20">
                                        <div
                                            class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 mb-1.5">
                                            Resolution</div>
                                        <p class="text-xs text-gray-700 dark:text-gray-300 leading-relaxed">
                                            <?= nl2br(htmlspecialchars($d['resolution_notes'])) ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php if (in_array($st, ['open', 'under_review', 'evidence_required'], true)): ?>
                                    <div
                                        class="mt-4 p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-start gap-2">
                                        <i class="fa-solid fa-circle-info text-amber-500 mt-0.5 text-xs"></i>
                                        <p class="text-[11px] text-amber-800 dark:text-amber-300 leading-relaxed">
                                            Contact support with order details, shipping proof, and communication logs. Respond
                                            within the deadline to strengthen your case.
                                        </p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
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