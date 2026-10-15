<?php
header('Content-Type: application/json');
require_once "../config/database.php";

$auction_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($auction_id <= 0) {
    echo json_encode(['error' => 'Invalid ID']);
    exit();
}

$stmt = $conn->prepare("
    SELECT current_bid, starting_price, bid_increment, end_time, auction_status,
           (SELECT COUNT(*) FROM bids WHERE auction_id = ?) AS total_bids
    FROM auctions
    WHERE auction_id = ?
");
$stmt->bind_param("ii", $auction_id, $auction_id);
$stmt->execute();
$auction = $stmt->get_result()->fetch_assoc();

if (!$auction) {
    echo json_encode(['error' => 'Not found']);
    exit();
}

$current_bid = floatval($auction['current_bid']);
$starting_price = floatval($auction['starting_price']);
$bid_increment = floatval($auction['bid_increment']);

$display_price = $current_bid > 0 ? $current_bid : $starting_price;
$minimum_bid = $display_price + $bid_increment;
$is_ended = strtotime($auction['end_time']) <= time() || $auction['auction_status'] === 'completed';

echo json_encode([
    'current_price' => $display_price,
    'current_price_formatted' => number_format($display_price),
    'minimum_bid' => $minimum_bid,
    'minimum_bid_formatted' => number_format($minimum_bid),
    'total_bids' => intval($auction['total_bids']),
    'is_ended' => $is_ended
]);