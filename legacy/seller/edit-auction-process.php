<?php
session_start();
date_default_timezone_set('Asia/Karachi');

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "seller") {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/activity.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: my-auctions.php");
    exit();
}

nb_csrf_check();

$seller_id = intval($_SESSION["user_id"]);

/* =========================================================
   ★ KYC GATE
========================================================= */
$kyc_chk = $conn->prepare("SELECT seller_kyc_status FROM users WHERE user_id = ? LIMIT 1");
$kyc_chk->bind_param("i", $seller_id);
$kyc_chk->execute();
$kyc_res = $kyc_chk->get_result()->fetch_assoc();
$kyc_chk->close();

if (($kyc_res['seller_kyc_status'] ?? 'unverified') !== 'approved') {
    $_SESSION["error"] = "Please complete seller KYC verification first.";
    header("Location: kyc-status.php");
    exit();
}

$auction_id = intval($_POST["auction_id"] ?? 0);
$action = $_POST["action"] ?? 'submit';
$title = trim($_POST["title"] ?? "");
$category_id = intval($_POST["category_id"] ?? 0);
$description = trim($_POST["description"] ?? "");
$starting_price = floatval($_POST["starting_price"] ?? 0);
$bid_increment = floatval($_POST["bid_increment"] ?? 0);
$start_time = $_POST["start_time"] ?? "";
$end_time = $_POST["end_time"] ?? "";

$is_draft = ($action === 'draft');
$redirect_back = "edit-auction.php?id=" . $auction_id;

/* Validation */
if ($auction_id <= 0) {
    $_SESSION["error"] = "Invalid auction.";
    header("Location: my-auctions.php");
    exit();
}

if (!$is_draft) {
    if ($title === "" || mb_strlen($title) > 120) {
        $_SESSION["error"] = "Title is required (max 120 characters).";
        header("Location: $redirect_back");
        exit();
    }
    if ($category_id <= 0) {
        $_SESSION["error"] = "Please select a category.";
        header("Location: $redirect_back");
        exit();
    }
    if ($description === "" || mb_strlen($description) > 2000) {
        $_SESSION["error"] = "Description is required (max 2000 characters).";
        header("Location: $redirect_back");
        exit();
    }
    if ($starting_price <= 0) {
        $_SESSION["error"] = "Starting price must be greater than 0.";
        header("Location: $redirect_back");
        exit();
    }
    if ($bid_increment <= 0) {
        $_SESSION["error"] = "Bid increment must be greater than 0.";
        header("Location: $redirect_back");
        exit();
    }
    if ($start_time === "" || $end_time === "") {
        $_SESSION["error"] = "Please set start and end times.";
        header("Location: $redirect_back");
        exit();
    }
    if (strtotime($end_time) <= strtotime($start_time)) {
        $_SESSION["error"] = "End time must be after start time.";
        header("Location: $redirect_back");
        exit();
    }
    if (strtotime($end_time) <= time()) {
        $_SESSION["error"] = "End time must be in the future.";
        header("Location: $redirect_back");
        exit();
    }
} else {
    if ($title === "")
        $title = "Untitled Draft";
    if ($category_id <= 0) {
        $first = $conn->query("SELECT category_id FROM categories WHERE status='active' ORDER BY category_id LIMIT 1")->fetch_assoc();
        $category_id = intval($first['category_id'] ?? 1);
    }
    if ($start_time === "")
        $start_time = date('Y-m-d H:i:s', strtotime('+1 hour'));
    if ($end_time === "")
        $end_time = date('Y-m-d H:i:s', strtotime('+7 days'));
}

if (!$is_draft) {
    $cat_stmt = $conn->prepare("SELECT category_id FROM categories WHERE category_id = ? AND status = 'active' LIMIT 1");
    $cat_stmt->bind_param("i", $category_id);
    $cat_stmt->execute();
    if ($cat_stmt->get_result()->num_rows === 0) {
        $_SESSION["error"] = "Selected category is not available.";
        header("Location: $redirect_back");
        exit();
    }
    $cat_stmt->close();
}

/* Ownership + status */
$check = $conn->prepare("
    SELECT a.auction_status,
        (SELECT COUNT(*) FROM bids WHERE bids.auction_id = a.auction_id) AS bid_count
    FROM auctions a
    WHERE a.auction_id = ? AND a.seller_id = ?
    LIMIT 1
");
$check->bind_param("ii", $auction_id, $seller_id);
$check->execute();
$row = $check->get_result()->fetch_assoc();
$check->close();

if (!$row) {
    $_SESSION["error"] = "Auction not found.";
    header("Location: my-auctions.php");
    exit();
}

$current_status = strtolower($row["auction_status"]);

if (intval($row["bid_count"]) > 0) {
    $_SESSION["error"] = "Cannot edit — auction already has bids.";
    header("Location: my-auctions.php");
    exit();
}
if (in_array($current_status, ['completed', 'ended', 'cancelled', 'rejected', 'sold', 'unsold', 'relisted'], true)) {
    $_SESSION["error"] = "This auction can no longer be edited.";
    header("Location: my-auctions.php");
    exit();
}

/* Image upload */
$file_name = null;
if (isset($_FILES["image"]) && $_FILES["image"]["error"] === UPLOAD_ERR_OK) {
    if ($_FILES["image"]["size"] > 5 * 1024 * 1024) {
        $_SESSION["error"] = "Image must be smaller than 5MB.";
        header("Location: $redirect_back");
        exit();
    }
    $allowed = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
    $real_mime = mime_content_type($_FILES["image"]["tmp_name"]);
    if (!array_key_exists($real_mime, $allowed) || !getimagesize($_FILES["image"]["tmp_name"])) {
        $_SESSION["error"] = "Only valid JPG, PNG, or WEBP images are allowed.";
        header("Location: $redirect_back");
        exit();
    }
    $upload_dir = "../uploads/auctions/";
    if (!is_dir($upload_dir))
        mkdir($upload_dir, 0777, true);
    $file_name = time() . "_" . uniqid() . "." . $allowed[$real_mime];
    if (!move_uploaded_file($_FILES["image"]["tmp_name"], $upload_dir . $file_name)) {
        $_SESSION["error"] = "Image upload failed. Please try again.";
        header("Location: $redirect_back");
        exit();
    }
    $old = $conn->prepare("SELECT image FROM auctions WHERE auction_id = ? AND seller_id = ?");
    $old->bind_param("ii", $auction_id, $seller_id);
    $old->execute();
    $old_img = $old->get_result()->fetch_assoc()["image"] ?? null;
    $old->close();
    if ($old_img && file_exists($upload_dir . $old_img))
        @unlink($upload_dir . $old_img);
}

/* New status */
$new_status = null;
if ($is_draft) {
    $new_status = 'draft';
} elseif ($current_status === 'draft') {
    $new_status = 'pending';
} else {
    $new_status = $current_status;
}

/* Update */
if ($file_name) {
    $sql = "UPDATE auctions
            SET title = ?, category_id = ?, description = ?, starting_price = ?,
                current_bid = ?, bid_increment = ?, start_time = ?, end_time = ?,
                auction_status = ?, image = ?
            WHERE auction_id = ? AND seller_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "sisdddssssii",
        $title,
        $category_id,
        $description,
        $starting_price,
        $starting_price,
        $bid_increment,
        $start_time,
        $end_time,
        $new_status,
        $file_name,
        $auction_id,
        $seller_id
    );
} else {
    $sql = "UPDATE auctions
            SET title = ?, category_id = ?, description = ?, starting_price = ?,
                current_bid = ?, bid_increment = ?, start_time = ?, end_time = ?,
                auction_status = ?
            WHERE auction_id = ? AND seller_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "sisdddsssii",
        $title,
        $category_id,
        $description,
        $starting_price,
        $starting_price,
        $bid_increment,
        $start_time,
        $end_time,
        $new_status,
        $auction_id,
        $seller_id
    );
}

if ($stmt->execute()) {
    if ($is_draft) {
        $_SESSION["success"] = "Draft updated.";
        nb_log_activity($conn, $seller_id, 'auction.draft_saved', "Saved draft #$auction_id: $title");
    } elseif ($current_status === 'draft') {
        $_SESSION["success"] = "Auction submitted for review.";
        nb_log_activity($conn, $seller_id, 'auction.submitted', "Submitted auction #$auction_id: $title");
    } else {
        $_SESSION["success"] = "Auction updated successfully.";
        nb_log_activity($conn, $seller_id, 'auction.updated', "Updated auction #$auction_id: $title");
    }
} else {
    $_SESSION["error"] = "Update failed. Please try again.";
}
$stmt->close();

header("Location: my-auctions.php");
exit();