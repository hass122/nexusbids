<?php
session_start();
date_default_timezone_set('Asia/Karachi');

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "seller") {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/activity.php";

$seller_id = intval($_SESSION["user_id"]);

/* =========================================================
   ★ KYC GATE
========================================================= */
$kyc_chk = $conn->prepare("SELECT seller_kyc_status FROM users WHERE user_id = ? LIMIT 1");
$kyc_chk->bind_param("i", $seller_id);
$kyc_chk->execute();
$kyc_res = $kyc_chk->get_result()->fetch_assoc();
$kyc_chk->close();

if (($kyc_res['seller_kyc_status'] ?? 'unverified') !== 'approved') {
    $_SESSION["error"] = "Please complete seller KYC verification first.";
    header("Location: kyc-status.php");
    exit();
}

$source_id = intval($_GET["id"] ?? $_POST["auction_id"] ?? 0);

if ($source_id <= 0) {
    $_SESSION["error"] = "Invalid auction.";
    header("Location: my-auctions.php");
    exit();
}

$stmt = $conn->prepare("SELECT * FROM auctions WHERE auction_id = ? AND seller_id = ? LIMIT 1");
$stmt->bind_param("ii", $source_id, $seller_id);
$stmt->execute();
$src = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$src) {
    $_SESSION["error"] = "Auction not found.";
    header("Location: my-auctions.php");
    exit();
}

$status = strtolower($src['auction_status']);
$allowed_relist = ['ended', 'completed', 'rejected', 'cancelled', 'unsold', 'sold'];
if (!in_array($status, $allowed_relist, true)) {
    $_SESSION["error"] = "Only ended, completed, rejected, cancelled, sold, or unsold auctions can be relisted.";
    header("Location: my-auctions.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    nb_csrf_check();

    $start_time = trim($_POST['start_time'] ?? '');
    $end_time = trim($_POST['end_time'] ?? '');
    $start_price = floatval($_POST['starting_price'] ?? $src['starting_price']);
    $increment = floatval($_POST['bid_increment'] ?? $src['bid_increment']);
    $as_draft = !empty($_POST['save_as_draft']);

    $is_draft = $as_draft;
    $new_status = $is_draft ? 'draft' : 'pending';

    if ($is_draft) {
        if ($start_time === '')
            $start_time = date('Y-m-d H:i:s', strtotime('+1 hour'));
        if ($end_time === '')
            $end_time = date('Y-m-d H:i:s', strtotime('+7 days'));
    } else {
        if (
            $start_time === '' || $end_time === '' ||
            strtotime($end_time) <= strtotime($start_time) ||
            strtotime($end_time) <= time()
        ) {
            $_SESSION["error"] = "Please provide valid start and end times (end must be in the future).";
            header("Location: relist-auction.php?id=" . $source_id);
            exit();
        }
    }

    if ($start_price <= 0 || $increment <= 0) {
        $_SESSION["error"] = "Price and increment must be greater than 0.";
        header("Location: relist-auction.php?id=" . $source_id);
        exit();
    }

    $cs = $conn->prepare("SELECT category_id FROM categories WHERE category_id = ? AND status = 'active' LIMIT 1");
    $cs->bind_param("i", $src['category_id']);
    $cs->execute();
    if ($cs->get_result()->num_rows === 0) {
        $_SESSION["error"] = "Original category is no longer active. Please create a new auction manually.";
        header("Location: my-auctions.php");
        exit();
    }
    $cs->close();

    $sql = "INSERT INTO auctions
            (seller_id, category_id, title, description, starting_price, current_bid,
             bid_increment, start_time, end_time, auction_status, image, relisted_from)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $new = $conn->prepare($sql);
    $new->bind_param(
        "iissdddssssi",
        $seller_id,
        $src['category_id'],
        $src['title'],
        $src['description'],
        $start_price,
        $start_price,
        $increment,
        $start_time,
        $end_time,
        $new_status,
        $src['image'],
        $source_id
    );

    if ($new->execute()) {
        $new_id = $new->insert_id;
        $new->close();

        $conn->query("UPDATE auctions SET auction_status = 'relisted' WHERE auction_id = $source_id AND auction_status IN ('ended','completed','rejected','cancelled','unsold')");

        nb_log_activity(
            $conn,
            $seller_id,
            $is_draft ? 'auction.relisted_draft' : 'auction.relisted',
            "Relisted auction #$source_id → #$new_id"
        );

        $_SESSION["success"] = $is_draft
            ? "Saved as draft. Resume anytime from My Auctions."
            : "Auction relisted successfully. Awaiting admin approval.";
        header("Location: my-auctions.php");
        exit();
    }
    $new->close();

    $_SESSION["error"] = "Could not relist auction. Please try again.";
    header("Location: relist-auction.php?id=" . $source_id);
    exit();
}

$default_start = date('Y-m-d\TH:i', strtotime('+10 minutes'));
$default_end = date('Y-m-d\TH:i', strtotime('+7 days'));
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relist Auction · NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = {darkMode:'class', theme:{extend:{fontFamily:{sans:['Inter','system-ui','sans-serif']}}}};</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>body{font-family:'Inter',system-ui,sans-serif}</style>
</head>
<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 antialiased min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-lg">
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-2xl overflow-hidden">

            <div class="p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                <span class="w-11 h-11 rounded-2xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center">
                    <i class="fa-solid fa-rotate text-lg"></i>
                </span>
                <div>
                    <h1 class="text-lg font-black">Relist Auction</h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Create a fresh listing from this one</p>
                </div>
            </div>

            <?php if (!empty($_SESSION['error'])): ?>
                    <div class="px-6 pt-4">
                        <div class="p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-xs text-rose-700 dark:text-rose-300">
                            <?= htmlspecialchars($_SESSION['error']);
                            unset($_SESSION['error']); ?>
                        </div>
                    </div>
            <?php endif; ?>

            <form method="POST" class="p-6 space-y-5">
                <?php nb_csrf_field(); ?>
                <input type="hidden" name="auction_id" value="<?= (int) $src['auction_id'] ?>">

                <!-- Source preview -->
                <div class="p-4 rounded-2xl bg-gray-50 dark:bg-slate-800/50 border border-gray-200 dark:border-gray-700 flex gap-3">
                    <?php if (!empty($src['image'])): ?>
                            <img src="../uploads/auctions/<?= htmlspecialchars($src['image']) ?>" class="w-16 h-16 rounded-xl object-cover shrink-0" alt="">
                    <?php endif; ?>
                    <div class="min-w-0 flex-1">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-0.5">Relisting</div>
                        <div class="font-bold text-sm line-clamp-2"><?= htmlspecialchars($src['title']) ?></div>
                    </div>
                </div>

                <!-- Price -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Starting Price <span class="text-rose-500">*</span></label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-xs font-bold">PKR</span>
                            <input type="number" name="starting_price" min="1" step="1" required
                                value="<?= (int) $src['starting_price'] ?>"
                                class="w-full pl-14 pr-3 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 tabular-nums">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Bid Increment <span class="text-rose-500">*</span></label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-xs font-bold">PKR</span>
                            <input type="number" name="bid_increment" min="1" step="1" required
                                value="<?= (int) $src['bid_increment'] ?>"
                                class="w-full pl-14 pr-3 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 tabular-nums">
                        </div>
                    </div>
                </div>

                <!-- Schedule -->
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">Start <span class="text-rose-500">*</span></label>
                    <input type="datetime-local" name="start_time" required value="<?= $default_start ?>"
                        class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">End <span class="text-rose-500">*</span></label>
                    <input type="datetime-local" name="end_time" required value="<?= $default_end ?>"
                        class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>

                <div class="p-3 rounded-xl bg-blue-500/10 border border-blue-500/20 flex items-start gap-2">
                    <i class="fa-solid fa-circle-info text-blue-500 text-xs mt-0.5"></i>
                    <p class="text-[11px] text-blue-800 dark:text-blue-300 leading-relaxed">
                        A new auction will be created (copy of title, description, and image). It will require admin approval again.
                    </p>
                </div>

                <div class="flex gap-2 pt-1">
                    <a href="my-auctions.php" class="flex-1 py-3 rounded-xl text-center text-sm font-semibold bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors">
                        Cancel
                    </a>
                    <button type="submit" name="save_as_draft" value="1"
                        class="flex-1 py-3 rounded-xl bg-amber-500/10 hover:bg-amber-500/20 text-amber-700 dark:text-amber-400 border border-amber-500/20 text-sm font-bold flex items-center justify-center gap-2">
                        <i class="fa-solid fa-floppy-disk"></i> Draft
                    </button>
                    <button type="submit"
                        class="flex-1 py-3 rounded-xl bg-violet-600 hover:bg-violet-500 text-white text-sm font-bold shadow-lg shadow-violet-600/30 flex items-center justify-center gap-2 transition-all">
                        <i class="fa-solid fa-rotate"></i> Relist
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>
</html>