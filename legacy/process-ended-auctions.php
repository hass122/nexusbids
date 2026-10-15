<?php
// 1. Ensure database connection exists
if (!isset($conn)) {
    require_once __DIR__ . '/config/database.php';
}

if (!function_exists('processEndedAuctions')) {
    function processEndedAuctions($conn) {
        if (!$conn || !($conn instanceof mysqli)) {
            return;
        }

        // Query active auctions whose time has expired
        $sql = "SELECT auction_id, current_bid 
                FROM auctions 
                WHERE auction_status = 'active' 
                  AND end_time <= NOW()";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("Process Ended Auctions Query Error: " . $conn->error);
            return;
        }
        
        if (!$stmt->execute()) {
            return;
        }

        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            while ($auction = $result->fetch_assoc()) {
                $auction_id = (int)$auction['auction_id'];

                // 2. Fetch highest bidder (Check bidder_id OR user_id)
                $bid_sql = "SELECT bid_id, 
                                   COALESCE(
                                       NULLIF(
                                           (SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='bids' AND COLUMN_NAME='bidder_id' AND TABLE_SCHEMA=DATABASE()), ''
                                       ), 
                                       'user_id'
                                   ) as user_col 
                            FROM bids WHERE auction_id = ? ORDER BY bid_amount DESC, bid_time ASC LIMIT 1";

                // Standard fallback query to find the top bid
                $bid_stmt = $conn->prepare("
                    SELECT bidder_id, bid_amount 
                    FROM bids 
                    WHERE auction_id = ? 
                    ORDER BY bid_amount DESC, bid_time ASC 
                    LIMIT 1
                ");

                // Fallback if your table uses 'user_id' instead of 'bidder_id'
                if (!$bid_stmt) {
                    $bid_stmt = $conn->prepare("
                        SELECT user_id AS bidder_id, bid_amount 
                        FROM bids 
                        WHERE auction_id = ? 
                        ORDER BY bid_amount DESC, bid_time ASC 
                        LIMIT 1
                    ");
                }

                if ($bid_stmt) {
                    $bid_stmt->bind_param("i", $auction_id);
                    $bid_stmt->execute();
                    $top_bid = $bid_stmt->get_result()->fetch_assoc();

                    if ($top_bid && !empty($top_bid['bidder_id'])) {
                        $winner_id = (int)$top_bid['bidder_id'];
                        $winning_bid = (float)$top_bid['bid_amount'];

                        // 3. Insert or update winner record
                        $win_stmt = $conn->prepare("
                            INSERT INTO winners (auction_id, user_id, winning_bid, created_at) 
                            VALUES (?, ?, ?, NOW()) 
                            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), winning_bid = VALUES(winning_bid)
                        ");
                        
                        if ($win_stmt) {
                            $win_stmt->bind_param("iid", $auction_id, $winner_id, $winning_bid);
                            if (!$win_stmt->execute()) {
                                echo "<!-- Winner Insert Error: " . $win_stmt->error . " -->";
                            }
                        }

                        // 4. Update status to completed
                        $update_stmt = $conn->prepare("UPDATE auctions SET auction_status = 'completed' WHERE auction_id = ?");
                        if ($update_stmt) {
                            $update_stmt->bind_param("i", $auction_id);
                            $update_stmt->execute();
                        }
                    } else {
                        // No bids placed before time expired
                        $update_stmt = $conn->prepare("UPDATE auctions SET auction_status = 'ended' WHERE auction_id = ?");
                        if ($update_stmt) {
                            $update_stmt->bind_param("i", $auction_id);
                            $update_stmt->execute();
                        }
                    }
                }
            }
        }
    }
}

// Execute logic
if (isset($conn) && $conn instanceof mysqli) {
    processEndedAuctions($conn);
}