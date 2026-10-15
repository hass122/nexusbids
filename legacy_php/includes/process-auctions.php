<?php
/*
|--------------------------------------------------------------------------
| Process ended auctions and declare winners + create orders
|--------------------------------------------------------------------------
| FIXED: bind_param type string for orders INSERT corrected
|        ("siiiiddd" → "siiiidddd" to include $total_amount)
*/

if (!function_exists('processEndedAuctions')) {

    function processEndedAuctions($conn)
    {
        // ---------- Find ended auctions that need finalizing ----------
        $ended_result = $conn->query("
            SELECT auction_id
            FROM auctions
            WHERE auction_status = 'active'
              AND end_time <= NOW()
        ");

        if (!$ended_result || $ended_result->num_rows === 0) {
            return;
        }

        // Collect into an array first so we can close the result set
        $ended_ids = [];
        while ($row = $ended_result->fetch_assoc()) {
            $ended_ids[] = intval($row['auction_id']);
        }
        $ended_result->free();

        // ---------- Process each auction in its own transaction ----------
        foreach ($ended_ids as $auction_id) {

            $conn->begin_transaction();

            try {

                // ---- Re-lock the auction row to prevent double processing ----
                $lock = $conn->query("
                    SELECT auction_id, seller_id, current_bid, starting_price
                    FROM auctions
                    WHERE auction_id = $auction_id
                      AND auction_status = 'active'
                    FOR UPDATE
                ");

                if (!$lock || $lock->num_rows === 0) {
                    // Already finalized by another request — skip
                    $conn->rollback();
                    continue;
                }

                $auction = $lock->fetch_assoc();
                $lock->free();

                $seller_id = intval($auction['seller_id']);

                // ---- Find highest bid ----
                $bid_res = $conn->query("
                    SELECT bid_id, bidder_id, bid_amount, bid_time
                    FROM bids
                    WHERE auction_id = $auction_id
                    ORDER BY bid_amount DESC, bid_time ASC
                    LIMIT 1
                ");

                $highest_bid = ($bid_res && $bid_res->num_rows > 0)
                    ? $bid_res->fetch_assoc()
                    : null;

                // ============================================================
                // CASE A — No bids: mark auction ended, no winner, no order
                // ============================================================
                if (!$highest_bid) {

                    $conn->query("
                        UPDATE auctions
                        SET auction_status = 'ended'
                        WHERE auction_id = $auction_id
                    ");

                    $conn->commit();
                    continue;
                }

                // ============================================================
                // CASE B — Has bids: declare winner, create order
                // ============================================================

                $winner_id = intval($highest_bid['bidder_id']);
                $winning_bid = floatval($highest_bid['bid_amount']);

                // ---- 1. Insert winner (idempotent via UNIQUE) ----
                $ins_w = $conn->prepare("
                    INSERT INTO winners
                        (auction_id, user_id, winning_bid, status, created_at)
                    VALUES
                        (?, ?, ?, 'pending_payment', NOW())
                    ON DUPLICATE KEY UPDATE
                        winning_bid = VALUES(winning_bid),
                        user_id     = VALUES(user_id)
                ");
                if (!$ins_w) {
                    throw new RuntimeException("Winner prepare failed: " . $conn->error);
                }
                $ins_w->bind_param("iid", $auction_id, $winner_id, $winning_bid);
                $ins_w->execute();
                $ins_w->close();

                // ---- 2. Fetch the winner_id we just inserted/updated ----
                $get_winner = $conn->query("
                    SELECT winner_id
                    FROM winners
                    WHERE auction_id = $auction_id
                    LIMIT 1
                ");
                $winner_row = $get_winner->fetch_assoc();
                $winner_pk = intval($winner_row['winner_id']);
                $get_winner->free();

                // ---- 3. Mark auction as completed ----
                $conn->query("
                    UPDATE auctions
                    SET auction_status = 'completed',
                        current_bid = $winning_bid
                    WHERE auction_id = $auction_id
                ");

                // ---- 4. Create order (idempotent via UNIQUE(auction_id)) ----
                // Commission: 5% platform fee
                $platform_fee_pct = 5.00;
                $platform_fee = round($winning_bid * ($platform_fee_pct / 100), 2);
                $seller_amount = round($winning_bid - $platform_fee, 2);
                $total_amount = $winning_bid;

                $order_number = 'NB-' . date('Y') . '-' . str_pad($auction_id, 5, '0', STR_PAD_LEFT);

                $ins_o = $conn->prepare("
                    INSERT INTO orders
                        (order_number, auction_id, winner_id, buyer_id, seller_id,
                         winning_bid, platform_fee, seller_amount, total_amount,
                         order_status, payment_status, shipping_status, created_at)
                    VALUES
                        (?, ?, ?, ?, ?,
                         ?, ?, ?, ?,
                         'pending_payment', 'unpaid', 'not_started', NOW())
                    ON DUPLICATE KEY UPDATE
                        winning_bid   = VALUES(winning_bid),
                        platform_fee  = VALUES(platform_fee),
                        seller_amount = VALUES(seller_amount),
                        total_amount  = VALUES(total_amount)
                ");
                if (!$ins_o) {
                    throw new RuntimeException("Order prepare failed: " . $conn->error);
                }

                // ✅ FIXED: 9 variables → "siiiidddd" (1 s + 4 i + 4 d)
                $ins_o->bind_param(
                    "siiiidddd",
                    $order_number,
                    $auction_id,
                    $winner_pk,
                    $winner_id,
                    $seller_id,
                    $winning_bid,
                    $platform_fee,
                    $seller_amount,
                    $total_amount
                );
                $ins_o->execute();
                $ins_o->close();

                // ---- 5. Notify winner ----
                if (function_exists('nb_notify')) {
                    nb_notify(
                        $conn,
                        $winner_id,
                        'won',
                        'You won an auction!',
                        'Winning bid: Rs. ' . number_format($winning_bid) . '. Complete payment to proceed.',
                        'checkout.php?auction_id=' . $auction_id
                    );
                }

                // ---- 6. Notify seller ----
                if (function_exists('nb_notify')) {
                    nb_notify(
                        $conn,
                        $seller_id,
                        'sale',
                        'Your auction sold!',
                        'Winning bid: Rs. ' . number_format($winning_bid) . '.',
                        'seller/dashboard.php'
                    );
                }

                $conn->commit();

            } catch (Throwable $e) {
                $conn->rollback();
                error_log("processEndedAuctions failed for auction_id=$auction_id: " . $e->getMessage());
            }
        }
    }
}