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

$cfg = nb_gateway_config('easypaisa');
if (!$cfg) {
    nb_gateway_die('Easypaisa unavailable', 'Easypaisa is not enabled yet.', '/checkout.php?auction_id=' . $auction_id);
}

$stmt = $conn->prepare("
    SELECT o.order_id, o.order_number, o.total_amount, o.payment_status
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

if ($order['payment_status'] === 'paid') {
    header("Location: " . nb_url('/user/order-details.php?id=' . $auction_id));
    exit();
}

/* ---------- Build Easypaisa POST ---------- */
$amount = (float) $order['total_amount'];
$order_ref = 'EP' . time() . random_int(1000, 9999);
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];

$return_url = $base_url . nb_url($cfg['return_url'] ?? '/payments/easypaisa/callback.php');

$params = [
    'storeId' => (string) $cfg['store_id'],
    'amount' => number_format($amount, 2, '.', ''),
    'postBackURL' => $return_url,
    'orderRefNum' => $order_ref,
    'expiryDate' => date('YmdHis', strtotime('+1 hour')),
    'paymentMethod' => 'MA_PAYMENT_METHOD',
    'autoRedirect' => '1',
    'emailAddr' => '',
    'mobileNum' => '',
];

/* Easypaisa signature: sort all params (excluding merchantHashedReq), join VALUES with '&', HMAC-SHA256 with hash_key */
$sorted = $params;
ksort($sorted);
$hash_string = '';
foreach ($sorted as $v) {
    // Easypaisa includes even empty fields in the hash (per docs)
    $hash_string .= ($hash_string === '' ? '' : '&') . (string) $v;
}
$params['merchantHashedReq'] = hash_hmac('sha256', $hash_string, (string) $cfg['hash_key']);

$gateway_url = ($cfg['mode'] ?? 'sandbox') === 'live' ? $cfg['live_url'] : $cfg['sandbox_url'];

/* Save payment initiation */
$conn->begin_transaction();
try {
    $chk = $conn->prepare("SELECT payment_id FROM payments WHERE auction_id = ? AND user_id = ? LIMIT 1");
    $chk->bind_param("ii", $auction_id, $user_id);
    $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();
    $chk->close();

    $order_id_int = (int) $order['order_id'];

    if ($existing) {
        $payment_id = (int) $existing['payment_id'];
        $upd = $conn->prepare("
            UPDATE payments
            SET order_id = ?, payment_method = 'easypaisa',
                transaction_id = ?, payment_status = 'processing',
                initiated_at = COALESCE(initiated_at, NOW())
            WHERE payment_id = ?
        ");
        $upd->bind_param("isi", $order_id_int, $order_ref, $payment_id);
        $upd->execute();
        $upd->close();
    } else {
        $ins = $conn->prepare("
            INSERT INTO payments
                (order_id, auction_id, user_id, amount, payment_method,
                 transaction_id, payment_status, initiated_at)
            VALUES (?, ?, ?, ?, 'easypaisa', ?, 'processing', NOW())
        ");
        $ins->bind_param("iiids", $order_id_int, $auction_id, $user_id, $amount, $order_ref);
        $ins->execute();
        $payment_id = (int) $ins->insert_id;
        $ins->close();
    }

    if (function_exists('nb_payment_log')) {
        nb_payment_log($conn, $payment_id, 'processing', 'Easypaisa initiation', $user_id, 'buyer');
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log("Easypaisa initiation failed: " . $e->getMessage());
    nb_gateway_die('Payment error', 'Could not initiate Easypaisa payment.', '/checkout.php?auction_id=' . $auction_id);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Redirecting to Easypaisa…</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>

<body class="bg-slate-50 flex items-center justify-center min-h-screen font-sans"
    onload="document.getElementById('epForm').submit();">
    <div class="text-center">
        <div
            class="w-16 h-16 mx-auto rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-2xl mb-4 animate-pulse">
            <i class="fa-solid fa-mobile-screen-button"></i>
        </div>
        <h1 class="text-lg font-bold text-slate-900">Connecting to Easypaisa…</h1>
        <p class="text-sm text-slate-500 mt-2">You'll be redirected to complete your payment.</p>
        <form id="epForm" method="POST" action="<?= htmlspecialchars($gateway_url, ENT_QUOTES, 'UTF-8') ?>"
            class="hidden">
            <?php foreach ($params as $k => $v): ?>
                <input type="hidden" name="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>"
                    value="<?= htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') ?>">
            <?php endforeach; ?>
        </form>
    </div>
</body>

</html>