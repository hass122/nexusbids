<?php

require_once __DIR__ . "/notifications.php";



function nb_resolve_auto_bids($conn, $auction_id)
{
    $auction_id = intval($auction_id);

    $safety_counter = 0;

    while ($safety_counter < 50) {

        $safety_counter++;

        $auction_stmt = $conn->prepare("
            SELECT
                title,
                current_bid,
                starting_price,
                bid_increment,
                auction_status,
                end_time
            FROM auctions
            WHERE auction_id = ?
        ");

        $auction_stmt->bind_param("i", $auction_id);
        $auction_stmt->execute();

        $auction = $auction_stmt->get_result()->fetch_assoc();

        if (!$auction || $auction["auction_status"] !== "active") {
            return;
        }

        if (strtotime($auction["end_time"]) <= time()) {
            return;
        }

        $current_price = floatval($auction["current_bid"]) > 0
            ? floatval($auction["current_bid"])
            : floatval($auction["starting_price"]);

        $increment = floatval($auction["bid_increment"]);

        // Who currently holds the top bid?
        $top_stmt = $conn->prepare("
            SELECT bidder_id
            FROM bids
            WHERE auction_id = ?
            ORDER BY bid_amount DESC, bid_time ASC
            LIMIT 1
        ");

        $top_stmt->bind_param("i", $auction_id);
        $top_stmt->execute();

        $top_row = $top_stmt->get_result()->fetch_assoc();
        $current_leader_id = $top_row ? intval($top_row["bidder_id"]) : 0;

        // Find the highest active auto-bid ceiling that isn't the leader
        $candidate_stmt = $conn->prepare("
            SELECT user_id, max_amount
            FROM auto_bids
            WHERE auction_id = ?
              AND status = 'active'
              AND user_id != ?
              AND max_amount > ?
            ORDER BY max_amount DESC, created_at ASC
            LIMIT 1
        ");

        $candidate_stmt->bind_param(
            "iid",
            $auction_id,
            $current_leader_id,
            $current_price
        );

        $candidate_stmt->execute();

        $candidate = $candidate_stmt->get_result()->fetch_assoc();

        if (!$candidate) {
            return; // nobody else can outbid — stable state reached
        }

        $challenger_id = intval($candidate["user_id"]);
        $challenger_max = floatval($candidate["max_amount"]);

        $new_bid_amount = min($challenger_max, $current_price + $increment);

        if ($new_bid_amount <= $current_price) {
            return; // rounding edge case — nothing more to do
        }

        // Place the automatic bid
        $insert_stmt = $conn->prepare("
            INSERT INTO bids (auction_id, bidder_id, bid_amount, is_auto_bid, bid_time)
            VALUES (?, ?, ?, 1, NOW())
        ");

        $insert_stmt->bind_param(
            "iid",
            $auction_id,
            $challenger_id,
            $new_bid_amount
        );

        $insert_stmt->execute();

        $update_stmt = $conn->prepare("
            UPDATE auctions
            SET current_bid = ?
            WHERE auction_id = ?
        ");

        $update_stmt->bind_param("di", $new_bid_amount, $auction_id);
        $update_stmt->execute();

        // Notify whoever just got overtaken
        if ($current_leader_id > 0 && $current_leader_id !== $challenger_id) {

            nb_notify(
                $conn,
                $current_leader_id,
                "outbid",
                "You've been outbid",
                "Someone placed a higher bid on \"" . $auction["title"] . "\". Current bid: Rs. " . number_format($new_bid_amount),
                "auction-details.php?id=" . $auction_id
            );
        }

        // Loop again — the new leader might themselves get outbid by
        // another auto-bidder (or by their own previous ceiling if the
        // original leader also had one set).
    }
}


/*
|--------------------------------------------------------------------------
| Set or update a user's auto-bid ceiling, then immediately resolve
|--------------------------------------------------------------------------
*/

function nb_set_auto_bid($conn, $auction_id, $user_id, $max_amount)
{
    $stmt = $conn->prepare("
        INSERT INTO auto_bids (auction_id, user_id, max_amount, status)
        VALUES (?, ?, ?, 'active')
        ON DUPLICATE KEY UPDATE
            max_amount = VALUES(max_amount),
            status = 'active'
    ");

    $stmt->bind_param("iid", $auction_id, $user_id, $max_amount);
    $stmt->execute();

    nb_resolve_auto_bids($conn, $auction_id);
}
