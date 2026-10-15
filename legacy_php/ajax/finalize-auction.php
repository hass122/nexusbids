<?php
header('Content-Type: application/json');
date_default_timezone_set("Asia/Karachi");

require_once "../config/database.php";
if (isset($conn)) {
    $conn->query("SET time_zone = '+05:00'");
}

$auction_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($auction_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid auction ID']);
    exit();
}

// 1. Check current auction status
$stmt = $conn->prepare("SELECT auction_id, auction_status, end_time FROM auctions WHERE auction_id = ?");
$stmt->bind_param("i", $auction_id);
$stmt->execute();
$auction = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$auction) {
    echo json_encode(['success' => false, 'message' => 'Auction not found']);
    exit();
}

// If already completed, return existing winner
if ($auction['auction_status'] === 'completed') {
    $w_stmt = $conn->prepare("
        SELECT w.winning_bid, u.full_name 
        FROM winners w 
        INNER JOIN users u ON w.user_id = u.user_id 
        WHERE w.auction_id = ? 
        ORDER BY w.created_at DESC LIMIT 1
    ");
    $w_stmt->bind_param("i", $auction_id);
    $w_stmt->execute();
    $existing_winner = $w_stmt->get_result()->fetch_assoc();
    $w_stmt->close();

    echo json_encode([
        'success' => true,
        'status' => 'completed',
        'winner_name' => $existing_winner['full_name'] ?? 'Winner',
        'winning_bid' => number_format($existing_winner['winning_bid'] ?? 0)
    ]);
    exit();
}

// 2. Find highest bidder
$bid_stmt = $conn->prepare("
    SELECT bidder_id, bid_amount 
    FROM bids 
    WHERE auction_id = ? 
    ORDER BY bid_amount DESC, bid_time ASC 
    LIMIT 1
");
$bid_stmt->bind_param("i", $auction_id);
$bid_stmt->execute();
$highest_bid = $bid_stmt->get_result()->fetch_assoc();
$bid_stmt->close();

if ($highest_bid) {
    $winner_id = intval($highest_bid['bidder_id']);
    $winning_bid = floatval($highest_bid['bid_amount']);

    // Insert into winners table
    $ins = $conn->prepare("
        INSERT INTO winners (auction_id, user_id, winning_bid, status, created_at)
        VALUES (?, ?, ?, 'pending', NOW())
        ON DUPLICATE KEY UPDATE winning_bid = VALUES(winning_bid)
    ");
    $ins->bind_param("iid", $auction_id, $winner_id, $winning_bid);
    $ins->execute();
    $ins->close();

    // Mark auction as completed
    $up = $conn->prepare("UPDATE auctions SET auction_status = 'completed', current_bid = ? WHERE auction_id = ?");
    $up->bind_param("di", $winning_bid, $auction_id);
    $up->execute();
    $up->close();

    // Get winner name
    $u_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
    $u_stmt->bind_param("i", $winner_id);
    $u_stmt->execute();
    $user = $u_stmt->get_result()->fetch_assoc();
    $u_stmt->close();

    echo json_encode([
        'success' => true,
        'status' => 'completed',
        'winner_name' => $user['full_name'] ?? 'Highest Bidder',
        'winning_bid' => number_format($winning_bid)
    ]);
} else {
    // Ended with 0 bids
    $conn->query("UPDATE auctions SET auction_status = 'ended' WHERE auction_id = {$auction_id}");
    echo json_encode(['success' => true, 'status' => 'ended']);
}
exit();