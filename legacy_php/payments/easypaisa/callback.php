<?php
require_once dirname(__DIR__) . "/_bootstrap.php";

$cfg = nb_gateway_config('easypaisa');
if (!$cfg) {
    nb_gateway_die('Gateway disabled', 'Easypaisa is not enabled.', '/user/orders.php');
}

$data = $_POST ?: $_GET;

$order_ref = (string) ($data['orderRefNum'] ?? '');
$amount = (float) ($data['amount'] ?? 0);
$status = (string) ($data['status'] ?? '');
$resp_code = (string) ($data['responseCode'] ?? '');
$secure = (string) ($data['merchantHashedReq'] ?? '');

if ($order_ref === '') {
    nb_gateway_die('Invalid callback', 'Missing order reference.', '/user/orders.php');
}

/* Verify signature — Easypaisa signs sorted VALUES (including empties), HMAC-SHA256 with hash_key */
$sorted = $data;
unset($sorted['merchantHashedReq']);
ksort($sorted);
$hash_string = '';
foreach ($sorted as $v) {
    $hash_string .= ($hash_string === '' ? '' : '&') . (string) $v;
}
$expected = hash_hmac('sha256', $hash_string, (string) $cfg['hash_key']);

if (!hash_equals($expected, $secure)) {
    error_log("Easypaisa signature mismatch for $order_ref");
    nb_gateway_die('Signature invalid', 'Could not verify payment.', '/user/orders.php');
}

/* Look up payment + order (need auction_id for redirect) */
$stmt = $conn->prepare("
    SELECT p.payment_id, p.amount, p.payment_status,
           o.order_id, o.buyer_id, o.auction_id
    FROM payments p
    LEFT JOIN orders o ON o.order_id = p.order_id
    WHERE p.transaction_id = ?
    LIMIT 1
");
$stmt->bind_param("s", $order_ref);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payment) {
    nb_gateway_die('Payment not found', 'No matching payment record.', '/user/orders.php');
}

if (abs($amount - (float) $payment['amount']) > 0.01) {
    error_log("Easypaisa amount mismatch for $order_ref");
    nb_gateway_die('Amount mismatch', 'Paid amount does not match.', '/user/orders.php');
}

$is_success = ($resp_code === '000' || $status === 'SUCCESS' || $status === 'Paid');

if ($is_success) {
    if (!function_exists('nb_payment_mark_paid')) {
        error_log('nb_payment_mark_paid missing');
        nb_gateway_die('System error', 'Payment helper unavailable.', '/user/orders.php');
    }

    $result = nb_payment_mark_paid($conn, (int) $payment['payment_id'], $order_ref, json_encode($data));

    if ($result['ok']) {
        if (!empty($payment['buyer_id']) && function_exists('nb_notify')) {
            nb_notify(
                $conn,
                (int) $payment['buyer_id'],
                'payment',
                'Payment confirmed',
                'Your Easypaisa payment has been confirmed.',
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
        nb_payment_log($conn, (int) $payment['payment_id'], 'failed', 'Easypaisa declined', null, 'gateway');
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log("Easypaisa failure log error: " . $e->getMessage());
}

nb_gateway_die('Payment failed', 'Easypaisa declined the payment.', '/checkout.php?auction_id=' . (int) $payment['auction_id']);