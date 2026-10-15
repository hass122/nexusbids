<?php
require_once "admin-auth.php";
require_once "../includes/csrf.php";
require_once "../includes/activity-log.php";

$processed = 0;
$errors = [];
$run = false;

/* Handle POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync') {
    nb_csrf_check();
    $run = true;

    $sql = "
        SELECT a.auction_id 
        FROM auctions a
        LEFT JOIN winners w ON a.auction_id = w.auction_id
        WHERE a.end_time <= NOW() 
          AND w.auction_id IS NULL
          AND a.auction_status IN ('active','approved','ended')
    ";
    $result = $conn->query($sql);

    if ($result) {
        while ($auction = $result->fetch_assoc()) {
            $auction_id = intval($auction['auction_id']);

            /* Get highest bid */
            $bid_stmt = $conn->prepare("
                SELECT bidder_id, bid_amount 
                FROM bids 
                WHERE auction_id = ? 
                ORDER BY bid_amount DESC, bid_time ASC 
                LIMIT 1
            ");
            $bid_stmt->bind_param("i", $auction_id);
            $bid_stmt->execute();
            $top_bid = $bid_stmt->get_result()->fetch_assoc();
            $bid_stmt->close();

            if ($top_bid) {
                $winner_id = intval($top_bid['bidder_id']);
                $winning_bid = floatval($top_bid['bid_amount']);

                $ins = $conn->prepare("
                    INSERT INTO winners (auction_id, user_id, winning_bid, status, created_at)
                    VALUES (?, ?, ?, 'pending', NOW())
                    ON DUPLICATE KEY UPDATE winning_bid = VALUES(winning_bid)
                ");
                $ins->bind_param("iid", $auction_id, $winner_id, $winning_bid);
                if ($ins->execute()) {
                    $processed++;
                }
                $ins->close();

                $conn->query("UPDATE auctions SET auction_status = 'completed' WHERE auction_id = $auction_id");
            }
        }
    }

    nb_log_admin_action(
        $conn,
        $_SESSION["user_id"],
        "Winners synced",
        "Manual sync processed {$processed} winner(s)"
    );
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync Winners · NexusBids Admin</title>
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
                    class="w-11 h-11 rounded-2xl bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                    <i class="fa-solid fa-rotate text-lg"></i>
                </span>
                <div>
                    <h1 class="text-lg font-black">Sync Winners</h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Fix missing winner records</p>
                </div>
            </div>

            <div class="p-6 space-y-4">

                <?php if ($run): ?>
                    <div
                        class="p-4 rounded-2xl <?= $processed > 0 ? 'bg-emerald-500/10 border-emerald-500/20' : 'bg-gray-500/10 border-gray-500/20' ?> border flex items-start gap-3">
                        <i
                            class="fa-solid <?= $processed > 0 ? 'fa-circle-check text-emerald-500' : 'fa-info-circle text-gray-500' ?> mt-0.5"></i>
                        <div class="text-xs">
                            <div class="font-bold mb-1">
                                <?= $processed > 0 ? 'Sync Complete!' : 'Nothing to Sync' ?>
                            </div>
                            <p
                                class="<?= $processed > 0 ? 'text-emerald-800 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400' ?>">
                                <?php if ($processed > 0): ?>
                                    Successfully synchronized <strong><?= $processed ?></strong> winner record(s).
                                <?php else: ?>
                                    No missing winners found. All ended auctions already have winner records.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <a href="winners.php"
                        class="block w-full py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold text-center transition">
                        <i class="fa-solid fa-arrow-right mr-2"></i> Go to Winners
                    </a>

                <?php else: ?>
                    <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/20 flex items-start gap-3">
                        <i class="fa-solid fa-info-circle text-amber-500 mt-0.5"></i>
                        <div class="text-xs text-amber-800 dark:text-amber-300 leading-relaxed">
                            <strong>What does this do?</strong><br>
                            Scans all ended auctions that don't have a winner record yet. For each, finds the highest bidder
                            and creates a winner record automatically.
                        </div>
                    </div>

                    <div class="p-4 rounded-2xl bg-blue-500/10 border border-blue-500/20 flex items-start gap-3">
                        <i class="fa-solid fa-shield-halved text-blue-500 mt-0.5"></i>
                        <p class="text-xs text-blue-800 dark:text-blue-300 leading-relaxed">
                            Safe operation — only adds missing records. Existing winners are not modified.
                        </p>
                    </div>

                    <form method="POST" class="space-y-3">
                        <?php nb_csrf_field(); ?>
                        <input type="hidden" name="action" value="sync">

                        <div class="flex gap-2">
                            <a href="winners.php"
                                class="flex-1 py-3 rounded-xl text-center text-sm font-semibold bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 transition">
                                Cancel
                            </a>
                            <button type="submit"
                                class="flex-1 py-3 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-sm font-bold shadow-lg shadow-amber-600/30 transition flex items-center justify-center gap-2">
                                <i class="fa-solid fa-rotate"></i>
                                Start Sync
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

            </div>
        </div>
    </div>
</body>

</html>