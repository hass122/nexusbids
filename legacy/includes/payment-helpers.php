<?php
/*
|--------------------------------------------------------------------------
| Payment Helpers
|--------------------------------------------------------------------------
| Central place for all payment-related utilities:
|   - Logging status changes to payment_history
|   - Updating payment + order statuses atomically
|   - Generating unique order/payment references
|   - Validating amounts, methods, and status transitions
*/

if (!function_exists('nb_payment_log')) {
    /**
     * Write a row to payment_history and update the payment's status.
     * Returns true on success, false on failure.
     */
    function nb_payment_log($conn, $payment_id, $new_status, $note = null, $actor_id = null, $actor_type = 'system')
    {
        // Fetch current status so we can record old_status
        $s = $conn->prepare("SELECT payment_status, order_id FROM payments WHERE payment_id = ? LIMIT 1");
        $s->bind_param("i", $payment_id);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();

        if (!$row)
            return false;

        $old_status = $row['payment_status'];
        $order_id = $row['order_id'];
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $ins = $conn->prepare("
            INSERT INTO payment_history
                (payment_id, order_id, old_status, new_status, note, actor_id, actor_type, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->bind_param(
            "iisssiss",
            $payment_id,
            $order_id,
            $old_status,
            $new_status,
            $note,
            $actor_id,
            $actor_type,
            $ip
        );
        $ok = $ins->execute();
        $ins->close();

        return $ok;
    }
}

if (!function_exists('nb_payment_mark_paid')) {
    /**
     * Atomically mark a payment as paid AND update the linked order.
     * Used by checkout AND by gateway callbacks.
     *
     * Returns ['ok' => bool, 'message' => string, 'payment_id' => int|null]
     */
    function nb_payment_mark_paid($conn, $payment_id, $transaction_id = null, $gateway_response = null)
    {
        $conn->begin_transaction();
        try {
            // Lock the payment row
            $lock = $conn->prepare("
                SELECT payment_id, order_id, payment_status
                FROM payments
                WHERE payment_id = ?
                FOR UPDATE
            ");
            $lock->bind_param("i", $payment_id);
            $lock->execute();
            $payment = $lock->get_result()->fetch_assoc();
            $lock->close();

            if (!$payment) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'Payment not found.'];
            }

            if ($payment['payment_status'] === 'paid') {
                // Already processed — idempotent success
                $conn->commit();
                return ['ok' => true, 'message' => 'Payment was already marked paid.', 'payment_id' => $payment_id];
            }

            // 1. Update payment
            $upd = $conn->prepare("
                UPDATE payments
                SET payment_status = 'paid',
                    transaction_id = COALESCE(?, transaction_id),
                    gateway_response = COALESCE(?, gateway_response)
                WHERE payment_id = ?
            ");
            $upd->bind_param("ssi", $transaction_id, $gateway_response, $payment_id);
            $upd->execute();
            $upd->close();

            // 2. Update order (if linked)
            if (!empty($payment['order_id'])) {
                $upd2 = $conn->prepare("
                    UPDATE orders
                    SET payment_status = 'paid',
                        order_status   = 'paid',
                        paid_at        = NOW()
                    WHERE order_id = ?
                ");
                $upd2->bind_param("i", $payment['order_id']);
                $upd2->execute();
                $upd2->close();

                // 3. Also update winners.status for consistency
                $upd3 = $conn->prepare("
                    UPDATE winners w
                    INNER JOIN orders o ON o.winner_id = w.winner_id
                    SET w.status = 'paid'
                    WHERE o.order_id = ?
                ");
                $upd3->bind_param("i", $payment['order_id']);
                $upd3->execute();
                $upd3->close();
            }

            // 4. Log history
            nb_payment_log(
                $conn,
                $payment_id,
                'paid',
                'Payment confirmed' . ($transaction_id ? " (TxID: $transaction_id)" : ''),
                null,
                'gateway'
            );

            $conn->commit();
            return ['ok' => true, 'message' => 'Payment marked as paid.', 'payment_id' => $payment_id];

        } catch (Throwable $e) {
            $conn->rollback();
            error_log("nb_payment_mark_paid failed: " . $e->getMessage());
            return ['ok' => false, 'message' => 'Payment update failed. Please try again.'];
        }
    }
}

if (!function_exists('nb_generate_order_number')) {
    /**
     * Generate a unique order number like NB-2026-00042.
     * Uses order_id as the suffix to guarantee uniqueness.
     */
    function nb_generate_order_number($order_id)
    {
        return 'NB-' . date('Y') . '-' . str_pad((int) $order_id, 5, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('nb_payment_methods')) {
    /**
     * Return the list of supported payment methods with metadata.
     */
    function nb_payment_methods()
    {
        return [
            'jazzcash' => [
                'label' => 'JazzCash',
                'icon' => 'fa-wallet',
                'color' => 'brand',
                'description' => 'Pay via JazzCash mobile wallet',
                'requires' => ['transaction_id', 'sender_name', 'sender_number'],
            ],
            'easypaisa' => [
                'label' => 'Easypaisa',
                'icon' => 'fa-mobile-screen-button',
                'color' => 'emerald',
                'description' => 'Pay via Easypaisa mobile wallet',
                'requires' => ['transaction_id', 'sender_name', 'sender_number'],
            ],
            'bank_transfer' => [
                'label' => 'Bank Transfer',
                'icon' => 'fa-building-columns',
                'color' => 'blue',
                'description' => 'Transfer via IBFT / online banking',
                'requires' => ['transaction_id', 'sender_name'],
            ],
            'card' => [
                'label' => 'Debit / Credit Card',
                'icon' => 'fa-credit-card',
                'color' => 'indigo',
                'description' => 'Visa, Mastercard via secure gateway',
                'requires' => ['transaction_id', 'sender_name'],
            ],
            'cash_on_delivery' => [
                'label' => 'Cash on Delivery',
                'icon' => 'fa-hand-holding-dollar',
                'color' => 'amber',
                'description' => 'Pay cash when the item arrives',
                'requires' => ['sender_name', 'sender_number'],
            ],
        ];
    }
}
?>