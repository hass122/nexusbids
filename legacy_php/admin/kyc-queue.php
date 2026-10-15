<?php
session_start();
date_default_timezone_set('Asia/Karachi');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";
require_once "../includes/kyc-helpers.php";

$admin_id = (int) $_SESSION['user_id'];

// Filters
$filter = $_GET['status'] ?? 'pending';
$search = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 20;

$valid_statuses = ['pending','under_review','approved','rejected','all'];
if (!in_array($filter, $valid_statuses, true)) $filter = 'pending';

// Build query
$where = [];
$params = [];
$types = '';

if ($filter !== 'all') {
    $where[] = "k.status = ?";
    $params[] = $filter;
    $types .= 's';
}
if ($search !== '') {
    $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR k.cnic_number_masked LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$cnt_sql = "SELECT COUNT(*) AS c FROM kyc_verifications k INNER JOIN users u ON u.user_id = k.user_id $where_sql";
$cnt_stmt = $conn->prepare($cnt_sql);
if ($types) $cnt_stmt->bind_param($types, ...$params);
$cnt_stmt->execute();
$total = (int) $cnt_stmt->get_result()->fetch_assoc()['c'];
$cnt_stmt->close();

$total_pages = max(1, (int) ceil($total / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

// Fetch
$sql = "
    SELECT k.kyc_id, k.user_id, k.full_name_on_cnic, k.cnic_number_masked,
           k.status, k.created_at, k.reviewed_at,
           u.full_name AS user_name, u.email AS user_email, u.avatar
    FROM kyc_verifications k
    INNER JOIN users u ON u.user_id = k.user_id
    $where_sql
    ORDER BY k.created_at DESC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);
$all_types = $types . 'ii';
$all_params = array_merge($params, [$per_page, $offset]);
$stmt->bind_param($all_types, ...$all_params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Stats
$stats = ['pending' => 0, 'under_review' => 0, 'approved' => 0, 'rejected' => 0];
$s = $conn->query("SELECT status, COUNT(*) AS c FROM kyc_verifications GROUP BY status");
if ($s) {
    while ($r = $s->fetch_assoc()) $stats[$r['status']] = (int) $r['c'];
}

$unread_notifications = nb_unread_count($conn, $admin_id);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>KYC Queue · Admin · NexusBids</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config = { darkMode: 'class', theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } } };
(function(){try{const s=localStorage.getItem('theme');const d=window.matchMedia('(prefers-color-scheme: dark)').matches;if(s==='dark'||(!s&&d))document.documentElement.classList.add('dark');}catch(e){}})();
</script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
<style>
    .glass{backdrop-filter:blur(16px) saturate(180%);-webkit-backdrop-filter:blur(16px) saturate(180%)}
    ::-webkit-scrollbar{width:8px;height:8px}::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:4px}
    .dark ::-webkit-scrollbar-thumb{background:#334155}
</style>
</head>
<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 font-sans antialiased min-h-screen">

<header class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
    <div class="max-w-[1600px] mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
        <a class="flex items-center gap-2.5 text-2xl font-black shrink-0" href="../index.php">
            <span class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg"><i class="fa-solid fa-gavel text-lg"></i></span>
            Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
            <span class="text-xs bg-rose-500 text-white px-2 py-0.5 rounded font-black ml-1">ADMIN</span>
        </a>
        <div class="flex items-center gap-2">
            <button id="themeToggle" class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                <i class="fa-solid fa-moon dark:hidden"></i><i class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
            </button>
            <a href="index.php" class="hidden sm:inline-flex px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:text-indigo-600 transition">
                <i class="fa-solid fa-arrow-left mr-1.5"></i> Admin Home
            </a>
        </div>
    </div>
</header>

<main class="max-w-[1600px] mx-auto px-4 sm:px-8 py-8 space-y-6">

    <!-- Header -->
    <div>
        <nav class="flex items-center gap-2 text-xs text-gray-500 mb-3">
            <a href="index.php" class="hover:text-indigo-600">Admin</a>
            <i class="fa-solid fa-chevron-right text-[8px]"></i>
            <span class="text-gray-900 dark:text-white font-semibold">KYC Queue</span>
        </nav>
        <h1 class="text-3xl font-black tracking-tight flex items-center gap-3">
            <span class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-500 flex items-center justify-center text-white shadow-lg"><i class="fa-solid fa-id-card text-lg"></i></span>
            KYC Verification Queue
        </h1>
        <p class="text-sm text-gray-500 mt-2">Review user identity documents and approve or reject submissions.</p>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <?php
        $stat_meta = [
            'pending' => ['label' => 'Pending', 'icon' => 'fa-hourglass-half', 'bg' => 'bg-amber-500/10', 'text' => 'text-amber-600 dark:text-amber-400'],
            'under_review' => ['label' => 'Under Review', 'icon' => 'fa-magnifying-glass', 'bg' => 'bg-blue-500/10', 'text' => 'text-blue-600 dark:text-blue-400'],
            'approved' => ['label' => 'Approved', 'icon' => 'fa-circle-check', 'bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-600 dark:text-emerald-400'],
            'rejected' => ['label' => 'Rejected', 'icon' => 'fa-circle-xmark', 'bg' => 'bg-rose-500/10', 'text' => 'text-rose-600 dark:text-rose-400'],
        ];
        foreach ($stat_meta as $key => $m): ?>
            <a href="?status=<?= $key ?>" class="bg-white dark:bg-slate-900 rounded-2xl p-5 border <?= $filter === $key ? 'border-indigo-500' : 'border-gray-200 dark:border-gray-800' ?> hover:border-indigo-500 transition">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[11px] font-bold text-gray-500 uppercase tracking-wider"><?= $m['label'] ?></span>
                    <span class="w-9 h-9 rounded-lg <?= $m['bg'] ?> <?= $m['text'] ?> flex items-center justify-center text-xs"><i class="fa-solid <?= $m['icon'] ?>"></i></span>
                </div>
                <div class="text-2xl font-black tabular-nums"><?= $stats[$key] ?? 0 ?></div>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-4">
        <form method="GET" class="flex flex-col sm:flex-row gap-3">
            <div class="relative flex-1">
                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name, email, or CNIC..." class="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <select name="status" class="py-2.5 px-4 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm" onchange="this.form.submit()">
                <option value="pending" <?= $filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="under_review" <?= $filter === 'under_review' ? 'selected' : '' ?>>Under Review</option>
                <option value="approved" <?= $filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                <option value="rejected" <?= $filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All</option>
            </select>
            <button type="submit" class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold whitespace-nowrap">
                <i class="fa-solid fa-filter mr-1.5"></i> Filter
            </button>
        </form>
    </div>

    <!-- Queue -->
    <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <?php if (empty($rows)): ?>
            <div class="p-16 text-center">
                <div class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-emerald-500/10 to-teal-500/10 text-emerald-500 flex items-center justify-center text-3xl mb-5">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <h3 class="text-xl font-black">All caught up!</h3>
                <p class="text-sm text-gray-500 mt-2">No KYC submissions in this queue.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <th class="px-6 py-4 text-left text-[10px] font-bold uppercase tracking-wider text-gray-500">User</th>
                            <th class="px-6 py-4 text-left text-[10px] font-bold uppercase tracking-wider text-gray-500">CNIC</th>
                            <th class="px-6 py-4 text-left text-[10px] font-bold uppercase tracking-wider text-gray-500">Status</th>
                            <th class="px-6 py-4 text-left text-[10px] font-bold uppercase tracking-wider text-gray-500">Submitted</th>
                            <th class="px-6 py-4 text-right text-[10px] font-bold uppercase tracking-wider text-gray-500">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <?php foreach ($rows as $r):
                            $m = nb_kyc_status_meta($r['status']);
                            $initials = strtoupper(substr(preg_replace('/\s+/', '', $r['user_name'] ?? 'U'), 0, 2));
                            ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-slate-800/50">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center"><?= htmlspecialchars($initials) ?></div>
                                        <div class="min-w-0">
                                            <div class="font-bold truncate"><?= htmlspecialchars($r['user_name']) ?></div>
                                            <div class="text-[11px] text-gray-500 truncate"><?= htmlspecialchars($r['user_email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-mono text-xs"><?= htmlspecialchars($r['cnic_number_masked']) ?></div>
                                    <div class="text-[11px] text-gray-500 mt-0.5"><?= htmlspecialchars($r['full_name_on_cnic']) ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?= $m['bg'] ?> <?= $m['text'] ?> text-[11px] font-bold border <?= $m['border'] ?>">
                                        <i class="fa-solid <?= $m['icon'] ?> text-[9px]"></i> <?= $m['label'] ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-xs text-gray-500">
                                    <?= date('d M Y', strtotime($r['created_at'])) ?>
                                    <div class="text-[10px]"><?= date('h:i A', strtotime($r['created_at'])) ?></div>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <a href="kyc-detail.php?id=<?= (int) $r['kyc_id'] ?>" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold">
                                        <i class="fa-regular fa-eye text-[10px]"></i> Review
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav class="flex flex-wrap items-center justify-between gap-4 p-5 border-t border-gray-100 dark:border-gray-800">
                    <p class="text-xs text-gray-500">Showing <?= count($rows) ?> of <strong><?= number_format($total) ?></strong></p>
                    <div class="flex items-center gap-1.5">
                        <?php
                        $qp = $_GET;
                        if ($page > 1) {
                            $qp['page'] = $page - 1;
                            echo '<a href="?' . htmlspecialchars(http_build_query($qp)) . '" class="w-9 h-9 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100 transition"><i class="fa-solid fa-chevron-left text-xs"></i></a>';
                        }
                        for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++):
                            $qp['page'] = $p;
                            $active = ($p === $page);
                            ?>
                            <a href="?<?= htmlspecialchars(http_build_query($qp)) ?>" class="w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold <?= $active ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100' ?>"><?= $p ?></a>
                        <?php endfor;
                        if ($page < $total_pages) {
                            $qp['page'] = $page + 1;
                            echo '<a href="?' . htmlspecialchars(http_build_query($qp)) . '" class="w-9 h-9 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100 transition"><i class="fa-solid fa-chevron-right text-xs"></i></a>';
                        }
                        ?>
                    </div>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<script>
(function(){
    const html=document.documentElement;
    const saved=localStorage.getItem('theme');
    if(saved==='dark'||(!saved&&window.matchMedia('(prefers-color-scheme: dark)').matches))html.classList.add('dark');
    document.getElementById('themeToggle')?.addEventListener('click',()=>{
        html.classList.toggle('dark');
        localStorage.setItem('theme',html.classList.contains('dark')?'dark':'light');
    });
})();
</script>
<?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>
</html>