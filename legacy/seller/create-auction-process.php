<?php
session_start();
date_default_timezone_set('Asia/Karachi');

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "seller") {
    header("Location: ../auth/login.php"); exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/activity.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: create-auction.php"); exit();
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
    $_SESSION["error"] = "Please complete seller KYC verification to create auctions.";
    header("Location: kyc-status.php");
    exit();
}

$draft_id       = intval($_POST["draft_id"] ?? 0);
$action         = $_POST["action"] ?? 'submit';
$title          = trim($_POST["title"] ?? "");
$category_id    = intval($_POST["category_id"] ?? 0);
$description    = trim($_POST["description"] ?? "");
$starting_price = floatval($_POST["starting_price"] ?? 0);
$bid_increment  = floatval($_POST["bid_increment"] ?? 0);
$start_time     = $_POST["start_time"] ?? "";
$end_time       = $_POST["end_time"] ?? "";
$remove_image   = !empty($_POST["remove_image"]);

$is_draft = ($action === 'draft');
$back     = $draft_id > 0 ? "create-auction.php?draft=" . $draft_id : "create-auction.php";

/* Validation */
if (!$is_draft) {
    if ($title === "" || mb_strlen($title) > 120) {
        $_SESSION["error"] = "Title is required (max 120 chars)."; header("Location: $back"); exit();
    }
    if ($category_id <= 0) { $_SESSION["error"] = "Please select a category."; header("Location: $back"); exit(); }
    if ($description === "" || mb_strlen($description) > 2000) {
        $_SESSION["error"] = "Description is required (max 2000 chars)."; header("Location: $back"); exit();
    }
    if ($starting_price <= 0) { $_SESSION["error"] = "Starting price must be > 0."; header("Location: $back"); exit(); }
    if ($bid_increment <= 0)  { $_SESSION["error"] = "Bid increment must be > 0."; header("Location: $back"); exit(); }
    if ($start_time === "" || $end_time === "") {
        $_SESSION["error"] = "Start and end times are required."; header("Location: $back"); exit();
    }
    if (strtotime($end_time) <= strtotime($start_time)) {
        $_SESSION["error"] = "End time must be after start."; header("Location: $back"); exit();
    }
    if (strtotime($end_time) <= time()) {
        $_SESSION["error"] = "End time must be in the future."; header("Location: $back"); exit();
    }
} else {
    if ($title === "") $title = "Untitled Draft";
    if ($category_id <= 0) {
        $first = $conn->query("SELECT category_id FROM categories WHERE status='active' ORDER BY category_id LIMIT 1")->fetch_assoc();
        $category_id = intval($first['category_id'] ?? 1);
    }
    if ($start_time === "") $start_time = date('Y-m-d H:i:s', strtotime('+1 hour'));
    if ($end_time === "")   $end_time   = date('Y-m-d H:i:s', strtotime('+7 days'));
}

if (!$is_draft) {
    $cat = $conn->prepare("SELECT category_id FROM categories WHERE category_id = ? AND status = 'active' LIMIT 1");
    $cat->bind_param("i", $category_id);
    $cat->execute();
    if ($cat->get_result()->num_rows === 0) {
        $_SESSION["error"] = "Selected category is not available."; header("Location: $back"); exit();
    }
    $cat->close();
}

/* Image handling */
$file_name = null;
$has_new_image = isset($_FILES["image"]) && $_FILES["image"]["error"] === UPLOAD_ERR_OK;

if ($has_new_image) {
    if ($_FILES["image"]["size"] > 5 * 1024 * 1024) {
        $_SESSION["error"] = "Image must be smaller than 5MB."; header("Location: $back"); exit();
    }
    $allowed = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
    $real_mime = mime_content_type($_FILES["image"]["tmp_name"]);
    if (!array_key_exists($real_mime, $allowed) || !getimagesize($_FILES["image"]["tmp_name"])) {
        $_SESSION["error"] = "Only valid JPG, PNG, WEBP images allowed."; header("Location: $back"); exit();
    }
    $upload_dir = "../uploads/auctions/";
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    $file_name = time() . "_" . uniqid() . "." . $allowed[$real_mime];
    if (!move_uploaded_file($_FILES["image"]["tmp_name"], $upload_dir . $file_name)) {
        $_SESSION["error"] = "Image upload failed."; header("Location: $back"); exit();
    }
} elseif ($draft_id > 0) {
    $old = $conn->prepare("SELECT image FROM auctions WHERE auction_id = ? AND seller_id = ?");
    $old->bind_param("ii", $draft_id, $seller_id);
    $old->execute();
    $file_name = $old->get_result()->fetch_assoc()['image'] ?? null;
    $old->close();
    if ($remove_image && $file_name && file_exists("../uploads/auctions/" . $file_name)) {
        @unlink("../uploads/auctions/" . $file_name);
        $file_name = null;
    }
} else {
    if (!$is_draft) { $_SESSION["error"] = "Please upload an auction image."; header("Location: $back"); exit(); }
}

$new_status = $is_draft ? 'draft' : 'pending';

/* Update existing draft */
if ($draft_id > 0) {
    $chk = $conn->prepare("SELECT auction_id FROM auctions WHERE auction_id = ? AND seller_id = ? AND auction_status = 'draft' LIMIT 1");
    $chk->bind_param("ii", $draft_id, $seller_id);
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) {
        $_SESSION["error"] = "Draft not found."; header("Location: my-auctions.php"); exit();
    }
    $chk->close();

    $sql = "UPDATE auctions SET
                category_id = ?, title = ?, description = ?, starting_price = ?,
                current_bid = ?, bid_increment = ?, start_time = ?, end_time = ?,
                auction_status = ?, image = COALESCE(?, image)
            WHERE auction_id = ? AND seller_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "issdddssssii",
        $category_id, $title, $description, $starting_price,
        $starting_price, $bid_increment, $start_time, $end_time,
        $new_status, $file_name, $draft_id, $seller_id
    );
    $stmt->execute();
    $stmt->close();

    $aid = $draft_id;
    $msg = $is_draft ? "Draft updated." : "Auction submitted for review.";
    nb_log_activity($conn, $seller_id, $is_draft ? 'auction.draft_saved' : 'auction.submitted',
        ($is_draft ? 'Updated draft ' : 'Submitted auction ') . "#$aid: $title");
} else {
    /* Insert new */
    $sql = "INSERT INTO auctions
            (seller_id, category_id, title, description, starting_price, current_bid,
             bid_increment, start_time, end_time, auction_status, image)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "iissdddssss",
        $seller_id, $category_id, $title, $description,
        $starting_price, $starting_price, $bid_increment,
        $start_time, $end_time, $new_status, $file_name
    );
    $stmt->execute();
    $aid = $stmt->insert_id;
    $stmt->close();

    $msg = $is_draft ? "Draft saved. Resume anytime from My Auctions." : "Auction submitted and awaiting approval.";
    nb_log_activity($conn, $seller_id, $is_draft ? 'auction.draft_created' : 'auction.created',
        ($is_draft ? 'Created draft ' : 'Created auction ') . "#$aid: $title");
}

$_SESSION["success"] = $msg;
header("Location: my-auctions.php");
exit();