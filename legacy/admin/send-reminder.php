<?php
/* =========================================================
   admin/send-reminder.php — Send Payment Reminder
   ---------------------------------------------------------
   Backend handler — no HTML output, redirects back to winners.php
   - Uses admin-auth.php for session + DB + auth
   - CSRF protected
   - Rate-limited (1 reminder per winner per 6 hours)
   ========================================================= */

require_once "admin-auth.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";

date_default_timezone_set("Asia/Karachi");

$admin_id  = (int) ($_SESSION["user_id"] ?? 0);
$winner_id = (int) ($_POST["winner_id"] ?? 0);

/* ---------- Validate winner_id ---------- */
if ($winner_id <= 0) {
    $_SESSION['winners_flash_error'] = "Invalid winner ID.";
    header("Location: winners.php");
    exit();
}

/* ---------- CSRF check (required for POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['winners_flash_error'] = "Invalid request method.";
    header("Location: winners.php");
    exit();
}
nb_csrf_check();

/* ---------- Rate-limit: 1 reminder per winner per 6 hours ---------- */
$last_remind_key = 'last_remind_' . $winner_id;
$last_remind_ts  = (int) ($_SESSION[$last_remind_key] ?? 0);
$cooldown        = 6 * 3600;

if ((time() - $last_remind_ts) < $cooldown) {
    $wait_min = (int) ceil(($cooldown - (time() - $last_remind_ts)) / 60);
    $_SESSION['winners_flash_error'] =
        "Reminder already sent recently. Please wait {$wait_min} more minute(s).";
    header("Location: winners.php");
    exit();
}

/* ---------- Fetch winner + auction + buyer + payment ---------- */
$winner = null;
if ($stmt = $conn->prepare("
    SELECT
        w.winner_id, w.winning_bid, w.user_id AS buyer_id, w.auction_id,
        w.status AS winner_status, w.created_at AS won_at,
        a.title AS auction_title,
        a.end_time,
        u.full_name AS buyer_name,
        u.email     AS buyer_email,
        p.payment_status,
        p.payment_id
    FROM winners w
    INNER JOIN auctions a ON a.auction_id = w.auction_id
    INNER JOIN users    u ON u.user_id    = w.user_id
    LEFT JOIN payments  p ON p.auction_id = w.auction_id AND p.user_id = w.user_id
    WHERE w.winner_id = ?
    LIMIT 1
")) {
    $stmt->bind_param("i", $winner_id);
    $stmt->execute();
    $winner = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$winner) {
    $_SESSION['winners_flash_error'] = "Winner #{$winner_id} not found.";
    header("Location: winners.php");
    exit();
}

$payment_status = $winner['payment_status'] ?? null;

/* Block if already paid */
if ($payment_status === 'paid') {
    $_SESSION['winners_flash_error'] = "Payment already completed. No reminder needed.";
    header("Location: winners.php");
    exit();
}

/* ---------- Build reminder ---------- */
$buyer_id      = (int) $winner['buyer_id'];
$auction_title = (string) ($winner['auction_title'] ?? 'your won auction');
$amount        = (float) ($winner['winning_bid'] ?? 0);
$auction_id    = (int) $winner['auction_id'];

$reminder_title = "Payment Reminder";
$reminder_msg   = 'Reminder: Please complete the payment of Rs. '
                . number_format($amount)
                . ' for "' . $auction_title . '". '
                . 'Your order is waiting to be processed.';

$link = 'user/order-details.php?id=' . $auction_id;

/* ---------- Send reminder ---------- */
$sent  = false;
$error = '';

try {
    if (function_exists('nb_notify')) {
        nb_notify(
            $conn,
            $buyer_id,
            'payment_reminder',
            $reminder_title,
            $reminder_msg,
            $link
        );
        $sent = true;
    } else {
        /* Fallback: direct insert */
        if ($n = $conn->prepare("
            INSERT INTO notifications
                (user_id, type, title, message, link, is_read, created_at)
            VALUES (?, 'payment_reminder', ?, ?, ?, 0, NOW())
        ")) {
            $n->bind_param("isss", $buyer_id, $reminder_title, $reminder_msg, $link);
            $n->execute();
            $n->close();
            $sent = true;
        } else {
            $error = "Could not insert notification: " . $conn->error;
        }
    }
} catch (Throwable $e) {
    error_log("send-reminder failed: " . $e->getMessage());
    $error = $e->getMessage();
}

/* ---------- Log to payment_history (optional, non-fatal) ---------- */
if ($sent && !empty($winner['payment_id']) && function_exists('nb_payment_log')) {
    try {
        nb_payment_log(
            $conn,
            (int) $winner['payment_id'],
            $payment_status ?: 'pending',
            "Payment reminder sent by admin #{$admin_id}",
            $admin_id,
            'admin'
        );
    } catch (Throwable $logErr) {
        error_log("nb_payment_log failed (non-fatal): " . $logErr->getMessage());
    }
}

/* ---------- Audit log (optional) ---------- */
if ($sent && function_exists('nb_pay_audit')) {
    try {
        nb_pay_audit($conn, [
            'actor_id'    => $admin_id,
            'actor_type'  => 'admin',
            'entity_type' => 'winner',
            'entity_id'   => $winner_id,
            'action'      => 'reminder.sent',
            'new'         => [
                'buyer_id' => $buyer_id,
                'amount'   => $amount,
            ],
        ]);
    } catch (Throwable $e) {}
}

/* ---------- Feedback & redirect ---------- */
if ($sent) {
    $_SESSION[$last_remind_key] = time();
    $_SESSION['winners_flash'] =
        "✅ Reminder sent to " . ($winner['buyer_name'] ?? 'buyer')
        . " for \"" . $auction_title . "\".";
} else {
    $_SESSION['winners_flash_error'] =
        "Could not send reminder. " . ($error ?: 'Please try again.');
}

header("Location: winners.php");
exit();