<?php
require_once "admin-auth.php";
require_once "../includes/csrf.php";
require_once "../includes/activity-log.php";

$id = intval($_GET['id'] ?? $_POST['auction_id'] ?? 0);
$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

if ($id <= 0 || !in_array($action, ['approve', 'reject', 'cancel'], true)) {
    header("Location: auctions.php");
    exit();
}

/* Fetch auction */
$stmt = $conn->prepare("
    SELECT a.*, u.full_name AS seller_name, u.email AS seller_email, c.category_name
    FROM auctions a
    INNER JOIN users u ON a.seller_id = u.user_id
    INNER JOIN categories c ON a.category_id = c.category_id
    WHERE a.auction_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$auction = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$auction) {
    $_SESSION['flash_error'] = "Auction not found.";
    header("Location: auctions.php");
    exit();
}

/* Handle POST — actually perform action */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nb_csrf_check();
    $note = trim($_POST['admin_note'] ?? '');

    /* Determine new status */
    if ($action === 'approve') {
        $new_status = (strtotime($auction['start_time']) <= time()) ? 'active' : 'approved';
    } elseif ($action === 'reject' || $action === 'cancel') {
        $new_status = 'cancelled';
    } else {
        header("Location: auctions.php");
        exit();
    }

    $upd = $conn->prepare("UPDATE auctions SET auction_status = ? WHERE auction_id = ?");
    $upd->bind_param("si", $new_status, $id);
    $upd->execute();
    $upd->close();

    /* Log activity */
    nb_log_admin_action(
        $conn,
        $_SESSION["user_id"],
        "Auction " . $action,
        "Auction #{$id} (\"" . $auction['title'] . "\") set to '{$new_status}'" . ($note ? " — {$note}" : "")
    );

    $_SESSION['flash_success'] = "Auction #{$id} " . ($action === 'approve' ? 'approved' : 'cancelled') . ".";
    header("Location: auctions.php");
    exit();
}

/* GET — show confirmation */
$action_labels = [
    'approve' => ['Approve Auction', 'fa-check', 'emerald', 'This will make the auction live (or scheduled if start time is in the future).'],
    'reject' => ['Reject Auction', 'fa-xmark', 'rose', 'This will cancel the auction. The seller will be notified.'],
    'cancel' => ['Cancel Auction', 'fa-ban', 'rose', 'This will cancel the live auction. Bidders will be notified.'],
];
[$title, $icon, $color, $desc] = $action_labels[$action];
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> · NexusBids Admin</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = { darkMode: 'class', theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } } };</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>

<body
    class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 font-sans antialiased min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-lg">
        <div
            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-2xl overflow-hidden">

            <div class="p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                <span
                    class="w-11 h-11 rounded-2xl bg-<?= $color ?>-500/10 text-<?= $color ?>-600 dark:text-<?= $color ?>-400 flex items-center justify-center">
                    <i class="fa-solid <?= $icon ?> text-lg"></i>
                </span>
                <div>
                    <h1 class="text-lg font-black"><?= htmlspecialchars($title) ?>?</h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Confirm this action</p>
                </div>
            </div>

            <div class="p-6 space-y-4">

                <!-- Auction preview -->
                <div
                    class="flex items-start gap-3 p-4 rounded-2xl bg-gray-50 dark:bg-slate-800/50 border border-gray-200 dark:border-gray-700">
                    <?php if (!empty($auction['image'])): ?>
                        <img src="../uploads/auctions/<?= htmlspecialchars($auction['image']) ?>"
                            class="w-16 h-16 rounded-xl object-cover shrink-0" alt="">
                    <?php else: ?>
                        <div
                            class="w-16 h-16 rounded-xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center text-lg shrink-0">
                            <i class="fa-solid fa-image"></i>
                        </div>
                    <?php endif; ?>
                    <div class="min-w-0 flex-1">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-0.5">
                            <?= htmlspecialchars($auction['category_name']) ?>
                        </div>
                        <div class="font-bold text-sm line-clamp-2"><?= htmlspecialchars($auction['title']) ?></div>
                        <div class="text-[10px] text-gray-500 mt-1">
                            by <?= htmlspecialchars($auction['seller_name']) ?>
                        </div>
                    </div>
                </div>

                <!-- Description -->
                <div
                    class="p-4 rounded-2xl bg-<?= $color ?>-500/10 border border-<?= $color ?>-500/20 flex items-start gap-3">
                    <i class="fa-solid fa-info-circle text-<?= $color ?>-500 mt-0.5"></i>
                    <p class="text-xs text-<?= $color ?>-800 dark:text-<?= $color ?>-300 leading-relaxed">
                        <?= htmlspecialchars($desc) ?>
                    </p>
                </div>

                <!-- Form -->
                <form method="POST" class="space-y-3">
                    <?php nb_csrf_field(); ?>
                    <input type="hidden" name="auction_id" value="<?= (int) $id ?>">
                    <input type="hidden" name="action" value="<?= htmlspecialchars($action) ?>">

                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Admin Note
                            (optional)</label>
                        <textarea name="admin_note" rows="2" maxlength="500"
                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"
                            placeholder="Optional note for internal record..."></textarea>
                    </div>

                    <div class="flex gap-2">
                        <a href="auctions.php"
                            class="flex-1 py-3 rounded-xl text-center text-sm font-semibold bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 transition">
                            Cancel
                        </a>
                        <button type="submit"
                            class="flex-1 py-3 rounded-xl bg-<?= $color ?>-600 hover:bg-<?= $color ?>-500 text-white text-sm font-bold shadow-lg shadow-<?= $color ?>-600/30 transition flex items-center justify-center gap-2">
                            <i class="fa-solid <?= $icon ?>"></i>
                            Yes, <?= htmlspecialchars(explode(' ', $title)[0]) ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>

</html>