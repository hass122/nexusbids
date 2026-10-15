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

$cfg = nb_gateway_config('jazzcash');
if (!$cfg) {
    nb_gateway_die('JazzCash unavailable', 'JazzCash is not enabled yet.', '/checkout.php?auction_id=' . $auction_id);
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

/* ---------- Build JazzCash POST ---------- */
$amount_paisas = (int) round((float) $order['total_amount'] * 100);
$txn_ref = 'T' . time() . random_int(1000, 9999);

$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$return_url = $base_url . nb_url($cfg['return_url'] ?? '/payments/jazzcash/callback.php');

$params = [
    'pp_Version' => '1.1',
    'pp_TxnType' => 'MWALLET',
    'pp_Language' => 'EN',
    'pp_MerchantID' => (string) $cfg['merchant_id'],
    'pp_SubMerchantID' => '',
    'pp_Password' => (string) $cfg['password'],
    'pp_BankID' => 'TBANK',
    'pp_ProductID' => 'RETL',
    'pp_TxnRefNo' => $txn_ref,
    'pp_Amount' => (string) $amount_paisas,
    'pp_TxnCurrency' => 'PKR',
    'pp_TxnDateTime' => date('YmdHis'),
    'pp_BillReference' => (string) $order['order_number'],
    'pp_Description' => 'NexusBids Order ' . $order['order_number'],
    'pp_TxnExpiryDateTime' => date('YmdHis', strtotime('+1 hour')),
    'pp_ReturnURL' => $return_url,
    'pp_SecureHash' => '',
];

/* JazzCash signature: HMAC-SHA256 over salt + sorted non-empty values, key = salt */
$sorted = $params;
ksort($sorted);
$hash_string = (string) $cfg['integrity_salt'];
foreach ($sorted as $k => $v) {
    if ($k === 'pp_SecureHash')
        continue;
    if ($v === '' || $v === null)
        continue; // JazzCash spec: skip empty
    $hash_string .= '&' . $v;
}
$params['pp_SecureHash'] = strtoupper(hash_hmac('sha256', $hash_string, (string) $cfg['integrity_salt']));

$gateway_url = (($cfg['mode'] ?? 'sandbox') === 'live' || getenv('PAYMENT_MODE') === 'live')
    ? $cfg['live_url']
    : $cfg['sandbox_url'];

/* Save payment initiation */
$conn->begin_transaction();
try {
    $chk = $conn->prepare("SELECT payment_id FROM payments WHERE auction_id = ? AND user_id = ? LIMIT 1");
    $chk->bind_param("ii", $auction_id, $user_id);
    $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();
    $chk->close();

    $order_id_int = (int) $order['order_id'];
    $amount = (float) $order['total_amount'];

    if ($existing) {
        $payment_id = (int) $existing['payment_id'];
        $upd = $conn->prepare("
            UPDATE payments
            SET order_id = ?, payment_method = 'jazzcash',
                transaction_id = ?, payment_status = 'processing',
                initiated_at = COALESCE(initiated_at, NOW())
            WHERE payment_id = ?
        ");
        $upd->bind_param("isi", $order_id_int, $txn_ref, $payment_id);
        $upd->execute();
        $upd->close();
    } else {
        $ins = $conn->prepare("
            INSERT INTO payments
                (order_id, auction_id, user_id, amount, payment_method,
                 transaction_id, payment_status, initiated_at)
            VALUES (?, ?, ?, ?, 'jazzcash', ?, 'processing', NOW())
        ");
        $ins->bind_param("iiids", $order_id_int, $auction_id, $user_id, $amount, $txn_ref);
        $ins->execute();
        $payment_id = (int) $ins->insert_id;
        $ins->close();
    }

    if (function_exists('nb_payment_log')) {
        nb_payment_log($conn, $payment_id, 'processing', 'JazzCash initiation', $user_id, 'buyer');
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log("JazzCash initiation failed: " . $e->getMessage());
    nb_gateway_die('Payment error', 'Could not initiate JazzCash payment.', '/checkout.php?auction_id=' . $auction_id);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Redirecting to JazzCash…</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>

<body class="bg-slate-50 flex items-center justify-center min-h-screen font-sans"
    onload="document.getElementById('jcForm').submit();">
    <div class="text-center">
        <div
            class="w-16 h-16 mx-auto rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-2xl mb-4 animate-pulse">
            <i class="fa-solid fa-wallet"></i>
        </div>
        <h1 class="text-lg font-bold text-slate-900">Connecting to JazzCash…</h1>
        <p class="text-sm text-slate-500 mt-2">You'll be redirected to complete your payment.</p>
        <form id="jcForm" method="POST" action="<?= htmlspecialchars($gateway_url, ENT_QUOTES, 'UTF-8') ?>"
            class="hidden">
            <?php foreach ($params as $k => $v): ?>
                <input type="hidden" name="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>"
                    value="<?= htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') ?>">
            <?php endforeach; ?>
        </form>
    </div>
</body>

</html>