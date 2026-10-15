<?php
/**
 * NexusBids — Email Event Hooks
 * 
 * Wire these to actual events across the app. Every function:
 *  - Fetches user email
 *  - Sends via nb_send_email()
 *  - Logs to email_log
 *  - Fails silently (never crashes the caller)
 */

require_once __DIR__ . '/email.php';

if (!function_exists('nb_email_event')) {

    /* =========================================================
       BID EVENTS
    ========================================================= */
    function nb_email_new_bid(mysqli $conn, int $auction_id, int $bidder_id, float $amount): void {
        try {
            $a = $conn->query("SELECT a.*, u.user_id AS seller_id, u.email AS seller_email, u.full_name AS seller_name
                               FROM auctions a INNER JOIN users u ON a.seller_id = u.user_id
                               WHERE a.auction_id = $auction_id LIMIT 1")->fetch_assoc();
            if (!$a) return;

            $subject = "New bid on: " . $a['title'];
            $body = "
                <p>Hi <strong>{$a['seller_name']}</strong>,</p>
                <p>You received a new bid of <strong>Rs. " . number_format($amount) . "</strong> on your auction:</p>
                <p style='padding:12px 16px;background:#f3f4f6;border-radius:8px;font-weight:600;'>{$a['title']}</p>
                <p>Current bid: Rs. " . number_format(max($amount, floatval($a['current_bid']))) . "</p>
                <p><a href='../seller/auction-analytics.php?id={$auction_id}' style='display:inline-block;padding:10px 20px;background:#6366f1;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;'>View Auction</a></p>
            ";
            nb_send_email($conn, intval($a['seller_id']), $a['seller_email'], $subject, $body, 'new_bid');
        } catch (Throwable $e) {
            error_log("nb_email_new_bid failed: " . $e->getMessage());
        }
    }

    function nb_email_outbid(mysqli $conn, int $auction_id, int $previous_bidder_id, float $new_amount): void {
        try {
            $u = nb_get_user_email($conn, $previous_bidder_id);
            if (!$u) return;
            $a = $conn->query("SELECT title FROM auctions WHERE auction_id = $auction_id LIMIT 1")->fetch_assoc();
            $title = $a['title'] ?? 'auction';

            $subject = "You've been outbid!";
            $body = "
                <p>Hi <strong>{$u['full_name']}</strong>,</p>
                <p>Someone placed a higher bid of <strong>Rs. " . number_format($new_amount) . "</strong> on:</p>
                <p style='padding:12px 16px;background:#f3f4f6;border-radius:8px;font-weight:600;'>{$title}</p>
                <p><a href='../auction-details.php?id={$auction_id}' style='display:inline-block;padding:10px 20px;background:#f43f5e;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;'>Bid Again</a></p>
            ";
            nb_send_email($conn, $previous_bidder_id, $u['email'], $subject, $body, 'bid_outbid');
        } catch (Throwable $e) {}
    }

    /* =========================================================
       AUCTION EVENTS
    ========================================================= */
    function nb_email_auction_approved(mysqli $conn, int $auction_id): void {
        try {
            $a = $conn->query("SELECT a.*, u.user_id AS seller_id, u.email AS seller_email, u.full_name AS seller_name
                               FROM auctions a INNER JOIN users u ON a.seller_id = u.user_id
                               WHERE a.auction_id = $auction_id LIMIT 1")->fetch_assoc();
            if (!$a) return;
            $subject = "✓ Your auction is approved: " . $a['title'];
            $body = "
                <p>Hi <strong>{$a['seller_name']}</strong>,</p>
                <p>Great news! Your auction has been approved and will go live soon.</p>
                <p style='padding:12px 16px;background:#ecfdf5;border:1px solid #10b981;border-radius:8px;font-weight:600;'>{$a['title']}</p>
                <p>We'll notify you as bids come in.</p>
                <p><a href='../seller/my-auctions.php' style='display:inline-block;padding:10px 20px;background:#10b981;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;'>View My Auctions</a></p>
            ";
            nb_send_email($conn, intval($a['seller_id']), $a['seller_email'], $subject, $body, 'auction_approved');
        } catch (Throwable $e) {}
    }

    function nb_email_auction_rejected(mysqli $conn, int $auction_id, string $reason = ''): void {
        try {
            $a = $conn->query("SELECT a.*, u.user_id AS seller_id, u.email AS seller_email, u.full_name AS seller_name
                               FROM auctions a INNER JOIN users u ON a.seller_id = u.user_id
                               WHERE a.auction_id = $auction_id LIMIT 1")->fetch_assoc();
            if (!$a) return;
            $reason_html = $reason ? "<p style='padding:12px 16px;background:#fef2f2;border:1px solid #f43f5e;border-radius:8px;'><strong>Reason:</strong> " . htmlspecialchars($reason) . "</p>" : '';
            $subject = "Auction needs attention: " . $a['title'];
            $body = "
                <p>Hi <strong>{$a['seller_name']}</strong>,</p>
                <p>Unfortunately your auction was rejected by our review team.</p>
                <p style='padding:12px 16px;background:#f3f4f6;border-radius:8px;font-weight:600;'>{$a['title']}</p>
                {$reason_html}
                <p>You can edit and resubmit it, or contact support for help.</p>
                <p><a href='../seller/my-auctions.php' style='display:inline-block;padding:10px 20px;background:#6366f1;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;'>Review Auction</a></p>
            ";
            nb_send_email($conn, intval($a['seller_id']), $a['seller_email'], $subject, $body, 'auction_rejected');
        } catch (Throwable $e) {}
    }

    function nb_email_auction_ending_soon(mysqli $conn, int $auction_id): void {
        try {
            $a = $conn->query("SELECT a.*, u.user_id AS seller_id, u.email AS seller_email, u.full_name AS seller_name
                               FROM auctions a INNER JOIN users u ON a.seller_id = u.user_id
                               WHERE a.auction_id = $auction_id LIMIT 1")->fetch_assoc();
            if (!$a) return;
            $subject = "⏰ Your auction ends in 2 hours: " . $a['title'];
            $body = "
                <p>Hi <strong>{$a['seller_name']}</strong>,</p>
                <p>Your auction is ending in less than 2 hours. Current bid: <strong>Rs. " . number_format(floatval($a['current_bid'])) . "</strong></p>
                <p style='padding:12px 16px;background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;font-weight:600;'>{$a['title']}</p>
                <p><a href='../seller/auction-analytics.php?id={$auction_id}' style='display:inline-block;padding:10px 20px;background:#f59e0b;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;'>Track Auction</a></p>
            ";
            nb_send_email($conn, intval($a['seller_id']), $a['seller_email'], $subject, $body, 'auction_ending_soon');
        } catch (Throwable $e) {}
    }

    /* =========================================================
       ORDER EVENTS
    ========================================================= */
    function nb_email_sale_made(mysqli $conn, int $order_id): void {
        try {
            $o = $conn->query("SELECT o.*, a.title, s.user_id AS seller_id, s.email AS seller_email, s.full_name AS seller_name
                               FROM orders o 
                               INNER JOIN auctions a ON o.auction_id = a.auction_id
                               INNER JOIN users s ON o.seller_id = s.user_id
                               WHERE o.order_id = $order_id LIMIT 1")->fetch_assoc();
            if (!$o) return;
            $subject = "🎉 You made a sale!";
            $body = "
                <p>Hi <strong>{$o['seller_name']}</strong>,</p>
                <p>Your auction sold. Time to ship it!</p>
                <p style='padding:12px 16px;background:#ecfdf5;border:1px solid #10b981;border-radius:8px;'>
                    <strong>{$o['title']}</strong><br>
                    Order #: " . htmlspecialchars($o['order_number'] ?? '#' . $order_id) . "<br>
                    Your earnings: <strong>Rs. " . number_format(floatval($o['seller_amount'] ?? 0)) . "</strong>
                </p>
                <p><a href='../seller/ship-order.php?id={$order_id}' style='display:inline-block;padding:10px 20px;background:#10b981;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;'>Ship Now</a></p>
            ";
            nb_send_email($conn, intval($o['seller_id']), $o['seller_email'], $subject, $body, 'sale');
        } catch (Throwable $e) {}
    }

    /* =========================================================
       PAYOUT EVENTS
    ========================================================= */
    function nb_email_payout_processed(mysqli $conn, int $payout_id, float $amount, string $status): void {
        try {
            // Payouts table column detection
            $has = $conn->query("SHOW TABLES LIKE 'payouts'")->num_rows > 0;
            if (!$has) return;
            $pid_col = 'payout_id';
            $r = $conn->query("SHOW COLUMNS FROM payouts LIKE 'payout_id'");
            if (!$r || $r->num_rows === 0) {
                $r2 = $conn->query("SHOW COLUMNS FROM payouts LIKE 'id'");
                if ($r2 && $r2->num_rows > 0) $pid_col = 'id';
            }
            $p = $conn->query("SELECT * FROM payouts WHERE `$pid_col` = $payout_id LIMIT 1")->fetch_assoc();
            if (!$p) return;

            $u = nb_get_user_email($conn, intval($p['seller_id'] ?? 0));
            if (!$u) return;

            $status_map = [
                'approved' => ['Your payout was approved', 'Your payout has been approved and will be processed shortly.', '#3b82f6'],
                'paid'     => ['✓ Payout processed', 'Your payout has been processed successfully.', '#10b981'],
                'rejected' => ['Payout rejected', 'Your payout request was rejected. Please contact support for details.', '#f43f5e'],
                'pending'  => ['Payout under review', 'Your payout request is being reviewed. This usually takes 2–3 business days.', '#f59e0b'],
            ];
            [$title, $msg, $color] = $status_map[$status] ?? ['Payout update', 'Your payout status has been updated.', '#6366f1'];

            $body = "
                <p>Hi <strong>{$u['full_name']}</strong>,</p>
                <p>{$msg}</p>
                <p style='padding:16px;background:{$color}15;border-left:4px solid {$color};border-radius:8px;font-size:18px;font-weight:700;'>Rs. " . number_format($amount) . "</p>
                <p><a href='../seller/payouts.php' style='display:inline-block;padding:10px 20px;background:{$color};color:#fff;text-decoration:none;border-radius:8px;font-weight:600;'>View Payouts</a></p>
            ";
            nb_send_email($conn, intval($p['seller_id']), $u['email'], $title, $body, 'payout');
        } catch (Throwable $e) {}
    }

    function nb_email_payout_requested(mysqli $conn, int $payout_id): void {
        nb_email_payout_processed($conn, $payout_id, 0, 'pending');
    }

    /* =========================================================
       KYC EVENTS
    ========================================================= */
    function nb_email_kyc_approved(mysqli $conn, int $user_id): void {
        try {
            $u = nb_get_user_email($conn, $user_id);
            if (!$u) return;
            $body = "
                <p>Hi <strong>{$u['full_name']}</strong>,</p>
                <p>Congratulations! Your identity has been <strong>verified</strong>. You now have a verified seller badge on your profile — this boosts buyer trust.</p>
                <p><a href='../seller/profile.php' style='display:inline-block;padding:10px 20px;background:#10b981;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;'>View Profile</a></p>
            ";
            nb_send_email($conn, $user_id, $u['email'], "✓ Your identity is verified", $body, 'kyc');
        } catch (Throwable $e) {}
    }

    function nb_email_kyc_rejected(mysqli $conn, int $user_id, string $reason = ''): void {
        try {
            $u = nb_get_user_email($conn, $user_id);
            if (!$u) return;
            $reason_html = $reason ? "<p style='padding:12px;background:#fef2f2;border-left:4px solid #f43f5e;border-radius:6px;'><strong>Reason:</strong> " . htmlspecialchars($reason) . "</p>" : '';
            $body = "
                <p>Hi <strong>{$u['full_name']}</strong>,</p>
                <p>Your KYC submission could not be verified. You can resubmit with corrected information.</p>
                {$reason_html}
                <p><a href='../seller/profile.php#kyc' style='display:inline-block;padding:10px 20px;background:#6366f1;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;'>Resubmit KYC</a></p>
            ";
            nb_send_email($conn, $user_id, $u['email'], "KYC needs attention", $body, 'kyc');
        } catch (Throwable $e) {}
    }

    /* =========================================================
       DISPUTE EVENTS
    ========================================================= */
    function nb_email_dispute_opened(mysqli $conn, int $dispute_id): void {
        try {
            $d = $conn->query("SELECT d.*, o.order_number, a.title AS auction_title,
                                      s.email AS seller_email, s.full_name AS seller_name, s.user_id AS seller_id
                               FROM disputes d
                               INNER JOIN orders o ON d.order_id = o.order_id
                               INNER JOIN auctions a ON o.auction_id = a.auction_id
                               INNER JOIN users s ON d.seller_id = s.user_id
                               WHERE d.dispute_id = $dispute_id LIMIT 1")->fetch_assoc();
            if (!$d) return;
            $body = "
                <p>Hi <strong>{$d['seller_name']}</strong>,</p>
                <p>A buyer has opened a dispute on one of your orders. Please review and respond.</p>
                <p style='padding:12px 16px;background:#fef2f2;border:1px solid #f43f5e;border-radius:8px;'>
                    <strong>Order:</strong> " . htmlspecialchars($d['order_number'] ?? '—') . "<br>
                    <strong>Auction:</strong> " . htmlspecialchars($d['auction_title'] ?? '—') . "<br>
                    <strong>Reason:</strong> " . htmlspecialchars($d['reason'] ?? '—') . "
                </p>
                <p><a href='../seller/disputes.php' style='display:inline-block;padding:10px 20px;background:#f43f5e;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;'>View Dispute</a></p>
            ";
            nb_send_email($conn, intval($d['seller_id']), $d['seller_email'], "⚠ New dispute on order " . ($d['order_number'] ?? ''), $body, 'dispute');
        } catch (Throwable $e) {}
    }
}