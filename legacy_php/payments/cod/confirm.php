<?php
require_once dirname(__DIR__) . "/_bootstrap.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: " . nb_url('/auth/login.php'));
    exit();
}

$user_id = (int) $_SESSION["user_id"];
$auction_id = (int) ($_GET["auction_id"] ?? 0);

if ($auction_id <= 0) {
    header("Location: " . nb_url('/user/orders.php'));
    exit();
}

$cfg = nb_gateway_config('cash_on_delivery');
if (!$cfg) {
    nb_gateway_die('Unavailable', 'Cash on Delivery is not available.', '/checkout.php?auction_id=' . $auction_id);
}

$stmt = $conn->prepare("
    SELECT o.order_id, o.order_number, o.total_amount, o.payment_status, o.order_status
    FROM orders o
    WHERE o.auction_id = ? AND o.buyer_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $auction_id, $user_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    nb_gateway_die('Order not found', 'This order could not be located.', '/user/orders.php');
}

$max_value = isset($cfg['max_order_value']) ? (float) $cfg['max_order_value'] : 0;
if ($max_value > 0 && (float) $order['total_amount'] > $max_value) {
    nb_gateway_die(
        'COD unavailable',
        'Cash on Delivery is only available for orders up to Rs. ' . number_format($max_value) . '.',
        '/checkout.php?auction_id=' . $auction_id
    );
}

$done = ($order['payment_status'] === 'pending' && $order['order_status'] === 'processing');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$done) {
    nb_csrf_check();

    $conn->begin_transaction();
    try {
        $chk = $conn->prepare("SELECT payment_id FROM payments WHERE auction_id = ? AND user_id = ? LIMIT 1");
        $chk->bind_param("ii", $auction_id, $user_id);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        $chk->close();

        $order_id_int = (int) $order['order_id'];
        $total_amount = (float) $order['total_amount'];

        if ($existing) {
            $payment_id = (int) $existing['payment_id'];
            $upd = $conn->prepare("
                UPDATE payments
                SET order_id = ?, payment_method = 'cash_on_delivery',
                    payment_status = 'pending', initiated_at = COALESCE(initiated_at, NOW())
                WHERE payment_id = ?
            ");
            $upd->bind_param("ii", $order_id_int, $payment_id);
            $upd->execute();
            $upd->close();
        } else {
            $ins = $conn->prepare("
                INSERT INTO payments
                    (order_id, auction_id, user_id, amount, payment_method, payment_status, initiated_at)
                VALUES (?, ?, ?, ?, 'cash_on_delivery', 'pending', NOW())
            ");
            $ins->bind_param("iiid", $order_id_int, $auction_id, $user_id, $total_amount);
            $ins->execute();
            $payment_id = (int) $ins->insert_id;
            $ins->close();
        }

        $upd = $conn->prepare("UPDATE orders SET order_status = 'processing', payment_status = 'pending' WHERE order_id = ?");
        $upd->bind_param("i", $order_id_int);
        $upd->execute();
        $upd->close();

        if (function_exists('nb_payment_log')) {
            nb_payment_log($conn, $payment_id, 'pending', 'Cash on delivery confirmed', $user_id, 'buyer');
        }

        if (function_exists('nb_notify')) {
            nb_notify(
                $conn,
                $user_id,
                'order',
                'Order confirmed (COD)',
                'Your cash-on-delivery order is confirmed. Pay when it arrives.',
                'user/order-details.php?id=' . $auction_id
            );
        }

        $conn->commit();
        $done = true;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("COD confirm failed: " . $e->getMessage());
        nb_gateway_die('Error', 'Could not confirm your order.', '/checkout.php?auction_id=' . $auction_id);
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash on Delivery · NexusBids</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
</head>

<body class="bg-slate-50 font-sans min-h-screen py-12 px-4">
    <div class="max-w-lg mx-auto">
        <a href="<?= htmlspecialchars(nb_url('/checkout.php?auction_id=' . $auction_id), ENT_QUOTES, 'UTF-8') ?>"
            class="inline-flex items-center text-sm font-semibold text-indigo-600 hover:underline mb-6">
            <i class="fa-solid fa-arrow-left mr-2"></i> Back to Checkout
        </a>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-8">
            <div
                class="w-16 h-16 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center text-2xl mb-4 mx-auto">
                <i class="fa-solid fa-hand-holding-dollar"></i>
            </div>
            <h1 class="text-2xl font-black text-center text-slate-900 mb-2">Cash on Delivery</h1>
            <p class="text-sm text-slate-500 text-center mb-6">
                Order <span
                    class="font-mono"><?= htmlspecialchars((string) $order['order_number'], ENT_QUOTES, 'UTF-8') ?></span>
            </p>

            <?php if ($done): ?>
                <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-center">
                    <i class="fa-solid fa-circle-check text-emerald-600 text-2xl mb-2"></i>
                    <p class="font-bold text-emerald-900">Order Confirmed!</p>
                    <p class="text-xs text-emerald-700 mt-1">
                        Pay Rs. <?= number_format((float) $order['total_amount']) ?> in cash to the courier.
                    </p>
                </div>
                <a href="<?= htmlspecialchars(nb_url('/user/order-details.php?id=' . $auction_id), ENT_QUOTES, 'UTF-8') ?>"
                    class="block mt-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-sm font-semibold text-center">
                    <i class="fa-solid fa-receipt mr-2"></i> View Order Details
                </a>
            <?php else: ?>
                <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 mb-6">
                    <h3 class="font-bold text-sm text-amber-900 mb-2">How it works</h3>
                    <ol class="text-xs text-amber-800 space-y-1 list-decimal list-inside">
                        <?php foreach (($cfg['instructions'] ?? []) as $step): ?>
                            <li><?= htmlspecialchars((string) $step, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ol>
                </div>
                <form method="POST">
                    <?php nb_csrf_field(); ?>
                    <button type="submit"
                        class="w-full py-3.5 bg-amber-500 hover:bg-amber-600 text-white rounded-xl font-bold text-sm">
                        <i class="fa-solid fa-check mr-2"></i> Confirm COD Order
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>