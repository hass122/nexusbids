<?php
session_start();
require_once "config/database.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: auth/login.php");
    exit();
}

$user_id = intval($_SESSION["user_id"]);
$auction_id = intval($_GET["auction_id"] ?? 0);

if ($auction_id <= 0) {
    header("Location: user/orders.php");
    exit();
}

// Fetch order info for display
$stmt = $conn->prepare("
    SELECT o.order_number, o.total_amount, o.payment_status, o.order_status,
           a.title AS auction_title
    FROM orders o
    INNER JOIN auctions a ON a.auction_id = o.auction_id
    WHERE o.auction_id = ? AND o.buyer_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $auction_id, $user_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

$message = $_SESSION['checkout_success'] ?? "Payment details received.";
unset($_SESSION['checkout_success']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Submitted · NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>

<body
    class="bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-100 font-sans antialiased min-h-screen flex items-center justify-center px-4">

    <div
        class="max-w-lg w-full bg-white dark:bg-slate-900 rounded-3xl p-8 shadow-xl border border-slate-200 dark:border-slate-800 text-center">

        <div
            class="w-20 h-20 mx-auto rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-3xl mb-5">
            <i class="fa-solid fa-paper-plane"></i>
        </div>

        <h1 class="text-2xl font-black text-slate-900 dark:text-white mb-2">Payment Submitted!</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400 mb-6"><?php echo htmlspecialchars($message); ?></p>

        <?php if ($order): ?>
            <div class="bg-slate-50 dark:bg-slate-800/50 rounded-2xl p-5 text-left mb-6 space-y-2">
                <div class="flex justify-between text-xs">
                    <span class="text-slate-500 dark:text-slate-400">Order</span>
                    <span class="font-mono font-bold"><?php echo htmlspecialchars($order['order_number']); ?></span>
                </div>
                <div class="flex justify-between text-xs">
                    <span class="text-slate-500 dark:text-slate-400">Amount</span>
                    <span class="font-bold">Rs. <?php echo number_format((float) $order['total_amount']); ?></span>
                </div>
                <div class="flex justify-between text-xs">
                    <span class="text-slate-500 dark:text-slate-400">Status</span>
                    <span
                        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-amber-500/10 text-amber-600 dark:text-amber-400 text-[10px] font-bold">
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                        Awaiting verification
                    </span>
                </div>
            </div>
        <?php endif; ?>

        <div class="space-y-3">
            <a href="user/order-details.php?id=<?php echo $auction_id; ?>" class="block w-full py-3.5 ...">
                <i class="fa-solid fa-receipt mr-2"></i> View Order
            </a>
            <a href="user/orders.php"
                class="block w-full py-3 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-semibold text-sm transition-colors">
                Back to My Orders
            </a>
        </div>
    </div>
    <?php require_once __DIR__ . '/includes/chat-widget.php'; ?>

</body>

</html>