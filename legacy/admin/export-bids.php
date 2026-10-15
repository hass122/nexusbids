<?php

require_once "admin-auth.php";

header("Content-Type: text/csv");
header("Content-Disposition: attachment; filename=nexusbids-bids-" . date("Y-m-d") . ".csv");

$output = fopen("php://output", "w");

fputcsv($output, ["Bid ID", "Auction", "Bidder Name", "Bidder Email", "Amount", "Auto Bid", "Date/Time"]);

$bids = $conn->query("
    SELECT
        b.bid_id, a.title, u.full_name, u.email, b.bid_amount, b.is_auto_bid, b.bid_time
    FROM bids b
    INNER JOIN auctions a ON b.auction_id = a.auction_id
    INNER JOIN users u ON b.bidder_id = u.user_id
    ORDER BY b.bid_time DESC
");

while ($row = $bids->fetch_assoc()) {

    fputcsv($output, [
        $row["bid_id"],
        $row["title"],
        $row["full_name"],
        $row["email"],
        $row["bid_amount"],
        !empty($row["is_auto_bid"]) ? "Yes" : "No",
        $row["bid_time"],
    ]);
}

fclose($output);
exit();
