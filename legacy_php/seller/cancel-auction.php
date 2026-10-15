<?php

session_start();

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "seller") {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/activity.php";

date_default_timezone_set('Asia/Karachi');

$seller_id  = intval($_SESSION["user_id"]);
$auction_id = intval($_POST["auction_id"] ?? $_GET["id"] ?? 0);

if ($auction_id <= 0) {
    $_SESSION["error"] = "Invalid auction.";
    header("Location: my-auctions.php");
    exit();
}

/* Fetch auction + verify ownership */
$stmt = $conn->prepare("
    SELECT a.auction_id, a.title, a.auction_status,
           (SELECT COUNT(*) FROM bids WHERE bids.auction_id = a.auction_id) AS bid_count
    FROM auctions a
    WHERE a.auction_id = ? AND a.seller_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $auction_id, $seller_id);
$stmt->execute();
$auction = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$auction) {
    $_SESSION["error"] = "Auction not found.";
    header("Location: my-auctions.php");
    exit();
}

/* POST = actually cancel */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    nb_csrf_check();

    /* Blocked statuses (extended) */
    $blocked = ['completed', 'ended', 'cancelled', 'rejected', 'sold', 'unsold', 'relisted'];
    if (in_array(strtolower($auction["auction_status"]), $blocked, true)) {
        $_SESSION["error"] = "This auction can no longer be cancelled.";
        header("Location: my-auctions.php");
        exit();
    }

    /* Cannot cancel if bids exist */
    if (intval($auction["bid_count"]) > 0) {
        $_SESSION["error"] = "This auction already has bids and can no longer be cancelled.";
        header("Location: my-auctions.php");
        exit();
    }

    $upd = $conn->prepare("
        UPDATE auctions
        SET auction_status = 'cancelled',
            cancelled_at   = NOW()
        WHERE auction_id = ? AND seller_id = ?
          AND auction_status NOT IN ('completed','ended','cancelled','rejected','sold','unsold','relisted')
    ");
    $upd->bind_param("ii", $auction_id, $seller_id);

    if ($upd->execute() && $upd->affected_rows > 0) {
        nb_log_activity($conn, $seller_id, 'auction.cancelled',
            "Cancelled auction #{$auction_id}: {$auction['title']}");
        $_SESSION["success"] = "Auction cancelled successfully.";
    } else {
        $_SESSION["error"] = "Could not cancel the auction. Please try again.";
    }
    $upd->close();

    header("Location: my-auctions.php");
    exit();
}

/* GET = show confirmation page */
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancel Auction · NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = {darkMode: 'class'};</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
        html.dark { color-scheme: dark; }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-md">
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-2xl overflow-hidden">

            <div class="p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                <span class="w-11 h-11 rounded-2xl bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center">
                    <i class="fa-solid fa-triangle-exclamation text-lg"></i>
                </span>
                <div>
                    <h1 class="text-lg font-black">Cancel Auction?</h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400">This action cannot be undone.</p>
                </div>
            </div>

            <div class="p-6 space-y-4">
                <div class="p-4 rounded-2xl bg-gray-50 dark:bg-slate-800/50 border border-gray-200 dark:border-gray-700">
                    <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1">Auction</div>
                    <div class="font-bold text-sm line-clamp-2"><?= htmlspecialchars($auction['title']) ?></div>
                    <div class="text-[10px] text-gray-400 mt-1 font-mono">
                        #<?= str_pad($auction['auction_id'], 5, '0', STR_PAD_LEFT) ?>
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/20 flex items-start gap-3">
                    <i class="fa-solid fa-info-circle text-amber-500 mt-0.5"></i>
                    <p class="text-xs text-amber-800 dark:text-amber-300 leading-relaxed">
                        Once cancelled, this auction will no longer be visible to bidders. You'll need to create a new listing or relist this one to sell this item.
                    </p>
                </div>

                <form method="POST" class="space-y-2">
                    <?php nb_csrf_field(); ?>
                    <input type="hidden" name="auction_id" value="<?= (int) $auction['auction_id'] ?>">

                    <button type="submit"
                        class="w-full py-3.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-bold text-sm shadow-lg shadow-rose-600/30 transition-all duration-200 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-ban"></i>
                        Yes, Cancel Auction
                    </button>

                    <a href="my-auctions.php"
                        class="block w-full py-3 rounded-xl text-center text-sm font-semibold text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-slate-800 transition-colors">
                        Keep Auction
                    </a>
                </form>
            </div>
        </div>
    </div>
<?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>