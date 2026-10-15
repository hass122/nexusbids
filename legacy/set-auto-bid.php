<?php
/**
 * NexusBids — Set Auto-Bid
 * KYC GATE: Only approved buyers can set auto-bids
 */
session_start();

require_once "config/database.php";
require_once "includes/csrf.php";
require_once "includes/auto-bid.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: auctions.php");
    exit();
}

nb_csrf_check();

if (!isset($_SESSION["user_id"])) {
    header("Location: auth/login.php");
    exit();
}

$user_id = intval($_SESSION["user_id"]);

/* =========================================================
   ★ KYC GATE
========================================================= */
$kyc_chk = $conn->prepare("SELECT kyc_status FROM users WHERE user_id = ? LIMIT 1");
$kyc_chk->bind_param("i", $user_id);
$kyc_chk->execute();
$kyc_res = $kyc_chk->get_result()->fetch_assoc();
$kyc_chk->close();

if (($kyc_res['kyc_status'] ?? 'unverified') !== 'approved') {
    $_SESSION["bid_error"] = "Please complete KYC verification before setting auto-bids.";
    $redirect = intval($_POST["auction_id"] ?? 0);
    header("Location: " . ($redirect ? "auction-details.php?id=$redirect" : "user/kyc-status.php"));
    exit();
}

$auction_id = intval($_POST["auction_id"] ?? 0);
$max_amount = floatval($_POST["max_amount"] ?? 0);

if ($auction_id <= 0) {
    header("Location: auctions.php");
    exit();
}

if ($max_amount <= 0) {
    $_SESSION["bid_error"] = "Please enter a valid maximum auto-bid amount.";
    header("Location: auction-details.php?id=" . $auction_id);
    exit();
}

$auction_stmt = $conn->prepare("
    SELECT seller_id, current_bid, starting_price, auction_status, end_time
    FROM auctions WHERE auction_id = ?
");
$auction_stmt->bind_param("i", $auction_id);
$auction_stmt->execute();
$auction = $auction_stmt->get_result()->fetch_assoc();
$auction_stmt->close();

if (!$auction) {
    header("Location: auctions.php");
    exit();
}

if (intval($auction["seller_id"]) === $user_id) {
    $_SESSION["bid_error"] = "You cannot bid on your own auction.";
    header("Location: auction-details.php?id=" . $auction_id);
    exit();
}

if ($auction["auction_status"] !== "active" || strtotime($auction["end_time"]) <= time()) {
    $_SESSION["bid_error"] = "This auction is not currently active.";
    header("Location: auction-details.php?id=" . $auction_id);
    exit();
}

$current_price = floatval($auction["current_bid"]) > 0
    ? floatval($auction["current_bid"])
    : floatval($auction["starting_price"]);

if ($max_amount <= $current_price) {
    $_SESSION["bid_error"] = "Your auto-bid maximum must be higher than the current bid (Rs. " . number_format($current_price) . ").";
    header("Location: auction-details.php?id=" . $auction_id);
    exit();
}

nb_set_auto_bid($conn, $auction_id, $user_id, $max_amount);

$_SESSION["bid_success"] = "Auto-bid set! We'll bid for you automatically up to Rs. " . number_format($max_amount) . ".";

header("Location: auction-details.php?id=" . $auction_id);
exit();