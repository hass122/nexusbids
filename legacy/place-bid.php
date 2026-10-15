<?php
/**
 * NexusBids — Place Bid Handler
 * KYC GATE: Only approved buyers can bid
 */
session_start();

require_once "config/database.php";
require_once "includes/csrf.php";
require_once "includes/rate-limit.php";
require_once "includes/notifications.php";
require_once "includes/auto-bid.php";
require_once "includes/ai-bid-fraud.php";

/* =========================================================
   ONLY POST
========================================================= */
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: auctions.php");
    exit();
}

nb_csrf_check();

/* =========================================================
   LOGIN REQUIRED
========================================================= */
if (!isset($_SESSION["user_id"])) {
    header("Location: auth/login.php");
    exit();
}

$user_id = intval($_SESSION["user_id"]);

/* =========================================================
   ★ KYC GATE — Only approved buyers can bid
========================================================= */
$kyc_stmt = $conn->prepare("SELECT kyc_status FROM users WHERE user_id = ? LIMIT 1");
$kyc_stmt->bind_param("i", $user_id);
$kyc_stmt->execute();
$kyc_row = $kyc_stmt->get_result()->fetch_assoc();
$kyc_stmt->close();
$user_kyc_status = $kyc_row['kyc_status'] ?? 'unverified';

if ($user_kyc_status !== 'approved') {
    $msg = match($user_kyc_status) {
        'pending'  => 'Your KYC is under review. You can bid once verified.',
        'rejected' => 'Your KYC was rejected. Please resubmit to bid.',
        default    => 'Please complete KYC verification before placing a bid.',
    };
    $_SESSION["bid_error"] = $msg;
    header("Location: user/kyc-status.php");
    exit();
}

/* =========================================================
   RATE LIMIT
========================================================= */
if (!nb_throttle("bid_" . $user_id, 15, 30)) {
    $_SESSION["bid_error"] = "You're bidding too fast — please slow down.";
    header("Location: auction-details.php?id=" . intval($_POST["auction_id"] ?? 0));
    exit();
}

$auction_id = isset($_POST["auction_id"]) ? intval($_POST["auction_id"]) : 0;
$bid_amount = isset($_POST["bid_amount"]) ? floatval($_POST["bid_amount"]) : 0;

/* =========================================================
   VALIDATE
========================================================= */
if ($auction_id <= 0) {
    header("Location: auctions.php");
    exit();
}

if ($bid_amount <= 0) {
    $_SESSION["bid_error"] = "Please enter a valid bid amount.";
    header("Location: auction-details.php?id=" . $auction_id);
    exit();
}

/* =========================================================
   TRANSACTION
========================================================= */
$conn->begin_transaction();

try {
    /* Lock auction */
    $stmt = $conn->prepare("
        SELECT auction_id, title, seller_id, starting_price, current_bid,
               bid_increment, auction_status, start_time, end_time
        FROM auctions WHERE auction_id = ? FOR UPDATE
    ");
    $stmt->bind_param("i", $auction_id);
    $stmt->execute();
    $auction = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$auction) throw new Exception("Auction not found.");

    if (intval($auction["seller_id"]) === $user_id) {
        throw new Exception("You cannot place a bid on your own auction.");
    }
    if ($auction["auction_status"] !== "active") {
        throw new Exception("This auction is not currently active.");
    }
    if (!empty($auction["start_time"]) && strtotime($auction["start_time"]) > time()) {
        throw new Exception("This auction has not started yet.");
    }
    if (empty($auction["end_time"]) || strtotime($auction["end_time"]) <= time()) {
        throw new Exception("This auction has already ended.");
    }

    $current_bid    = floatval($auction["current_bid"]);
    $starting_price = floatval($auction["starting_price"]);
    $bid_increment  = floatval($auction["bid_increment"]);

    $current_price  = ($current_bid <= 0) ? $starting_price : $current_bid;
    $minimum_bid    = $current_price + $bid_increment;

    if ($bid_amount < $minimum_bid) {
        throw new Exception("Your bid must be at least Rs. " . number_format($minimum_bid));
    }

    /* Previous top bidder */
    $previous_top_bidder_id = 0;
    $prev_stmt = $conn->prepare("
        SELECT bidder_id FROM bids
        WHERE auction_id = ?
        ORDER BY bid_amount DESC, bid_time ASC LIMIT 1
    ");
    $prev_stmt->bind_param("i", $auction_id);
    $prev_stmt->execute();
    $prev_row = $prev_stmt->get_result()->fetch_assoc();
    $prev_stmt->close();
    if ($prev_row) $previous_top_bidder_id = intval($prev_row["bidder_id"]);

    /* Insert bid */
    $bid_ip     = function_exists('nb_bidfraud_client_ip') ? nb_bidfraud_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
    $bid_device = function_exists('nb_bidfraud_device_hash') ? nb_bidfraud_device_hash() : '';

    $stmt = $conn->prepare("
        INSERT INTO bids (auction_id, bidder_id, bid_amount, bid_time, ip_address, device_hash)
        VALUES (?, ?, ?, NOW(), ?, ?)
    ");
    $stmt->bind_param("iidss", $auction_id, $user_id, $bid_amount, $bid_ip, $bid_device);
    $stmt->execute();
    $bid_id = $stmt->insert_id;
    $stmt->close();

    /* Update auction */
    $stmt = $conn->prepare("UPDATE auctions SET current_bid = ? WHERE auction_id = ?");
    $stmt->bind_param("di", $bid_amount, $auction_id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    /* AI fraud check (after commit) */
    try {
        if (function_exists('nb_bidfraud_check')) {
            $fraud = nb_bidfraud_check($conn, $auction_id, $bid_id, $user_id);
            if (!empty($fraud['alert_id'])) {
                error_log("Shill bid alert: auction=$auction_id bid=$bid_id bidder=$user_id score={$fraud['risk_score']}");
            }
        }
    } catch (Throwable $e) {
        error_log("bid-fraud check failed: " . $e->getMessage());
    }

    /* Notify previous top bidder */
    if ($previous_top_bidder_id > 0 && $previous_top_bidder_id !== $user_id) {
        nb_notify(
            $conn, $previous_top_bidder_id, "outbid",
            "You've been outbid",
            "Someone placed a higher bid on \"" . $auction["title"] . "\". Current bid: Rs. " . number_format($bid_amount),
            "auction-details.php?id=" . $auction_id
        );
    }

    /* Resolve auto-bids */
    if (function_exists('nb_resolve_auto_bids')) {
        nb_resolve_auto_bids($conn, $auction_id);
    }

    $_SESSION["bid_success"] = "Your bid of Rs. " . number_format($bid_amount) . " has been placed successfully!";
    header("Location: auction-details.php?id=" . $auction_id);
    exit();

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION["bid_error"] = $e->getMessage();
    header("Location: auction-details.php?id=" . $auction_id);
    exit();
}