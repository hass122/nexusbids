<?php
require_once dirname(__DIR__) . "/_bootstrap.php";

$cfg = nb_gateway_config('jazzcash');
if (!$cfg) {
    nb_gateway_die('Gateway disabled', 'JazzCash is not enabled.', '/user/orders.php');
}

$data      = $_POST ?: $_GET;
$txn_ref   = (string) ($data['pp_TxnRefNo'] ?? '');
$bill_ref  = (string) ($data['pp_BillReference'] ?? '');
$amount    = (int) ($data['pp_Amount'] ?? 0);   // paisa
$resp_code = (string) ($data['pp_ResponseCode'] ?? '');
$resp_msg  = (string) ($data['pp_ResponseMessage'] ?? 'Unknown');
$secure    = (string) ($data['pp_SecureHash'] ?? '');

if ($txn_ref === '') {
    nb_gateway_die('Invalid callback', 'Missing transaction reference.', '/user/orders.php');
}

/* Verify signature — JazzCash spec: salt + sorted NON-empty values, HMAC-SHA256 with salt */
$sorted = $data;
unset($sorted['pp_SecureHash']);
ksort($sorted);
$hash_string = (string) $cfg['integrity_salt'];
foreach ($sorted as $v) {
    if ($v === '' || $v === null) continue;
    $hash_string .= '&' . $v;
}
$expected = strtoupper(hash_hmac('sha256', $hash_string, (string) $cfg['integrity_salt']));

if (!hash_equals($expected, $secure)) {
    error_log("JazzCash signature mismatch for txn $txn_ref");
    nb_gateway_die('Signature invalid', 'We could not verify this payment.', '/user/orders.php');
}

/* Look up payment + order */
$stmt = $conn->prepare("
    SELECT p.payment_id, p.amount, p.payment_status,
           o.order_id, o.buyer_id, o.auction_id
    FROM payments p
    LEFT JOIN orders o ON o.order_id = p.order_id
    WHERE p.transaction_id = ?
    LIMIT 1
");
$stmt->bind_param("s", $txn_ref);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payment) {
    nb_gateway_die('Payment not found', 'No matching payment record.', '/user/orders.php');
}

/* Amount check: JazzCash sends paisa */
$expected_amount = (int) round((float) $payment['amount'] * 100);
if ($amount !== $expected_amount) {
    error_log("JazzCash amount mismatch: got $amount expected $expected_amount (txn $txn_ref)");
    nb_gateway_die('Amount mismatch', 'The paid amount does not match the order total.', '/user/orders.php');
}

$is_success = ($resp_code === '000');

if ($is_success) {
    if (!function_exists('nb_payment_mark_paid')) {
        error_log('nb_payment_mark_paid missing');
        nb_gateway_die('System error', 'Payment helper unavailable.', '/user/orders.php');
    }

    $result = nb_payment_mark_paid($conn, (int) $payment['payment_id'], $txn_ref, json_encode($data));

    if ($result['ok']) {
        if (!empty($payment['buyer_id']) && function_exists('nb_notify')) {
            nb_notify(
                $conn,
                (int) $payment['buyer_id'],
                'payment',
                'Payment confirmed',
                'Your JazzCash payment has been confirmed.',
                'user/order-details.php?id=' . (int) $payment['auction_id']
            );
        }
        header("Location: " . nb_url('/user/order-details.php?id=' . (int) $payment['auction_id']));
        exit();
    }
    nb_gateway_die('Update failed', $result['message'] ?? 'Unknown error', '/user/orders.php');
}

/* Failed */
$conn->begin_transaction();
try {
    $upd = $conn->prepare("UPDATE payments SET payment_status = 'failed' WHERE payment_id = ?");
    $upd->bind_param("i", $payment['payment_id']);
    $upd->execute();
    $upd->close();

    if (function_exists('nb_payment_log')) {
        nb_payment_log($conn, (int) $payment['payment_id'], 'failed', "JazzCash declined: $resp_msg", null, 'gateway');
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log("JazzCash failure log error: " . $e->getMessage());
}

nb_gateway_die(
    'Payment failed',
    'JazzCash declined the payment: ' . $resp_msg,
    '/checkout.php?auction_id=' . (int) $payment['auction_id']
);