<?php

session_start();

require_once "config/database.php";
require_once "includes/csrf.php";


/* ==========================================
   CHECK REQUEST METHOD
========================================== */

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    header("Location: auctions.php");
    exit();

}

nb_csrf_check();


/* ==========================================
   CHECK USER LOGIN
========================================== */

if (!isset($_SESSION["user_id"])) {

    $_SESSION["bid_error"] =
        "Please login first to add auctions to your watchlist.";

    header("Location: auth/login.php");
    exit();

}


/* ==========================================
   GET DATA
========================================== */

$user_id = intval($_SESSION["user_id"]);

$auction_id = isset($_POST["auction_id"])
    ? intval($_POST["auction_id"])
    : 0;


/* ==========================================
   VALIDATE AUCTION ID
========================================== */

if ($auction_id <= 0) {

    header("Location: auctions.php");
    exit();

}


/* ==========================================
   CHECK AUCTION EXISTS
========================================== */

$stmt = $conn->prepare("
    SELECT auction_id
    FROM auctions
    WHERE auction_id = ?
    LIMIT 1
");

$stmt->bind_param(
    "i",
    $auction_id
);

$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {

    $_SESSION["watchlist_error"] =
        "Auction not found.";

    header("Location: auctions.php");
    exit();

}

$stmt->close();


/* ==========================================
   CHECK IF ALREADY WATCHLISTED
========================================== */

$stmt = $conn->prepare("
    SELECT watchlist_id
    FROM watchlist
    WHERE user_id = ?
    AND auction_id = ?
    LIMIT 1
");

$stmt->bind_param(
    "ii",
    $user_id,
    $auction_id
);

$stmt->execute();

$result = $stmt->get_result();


if ($result->num_rows > 0) {

    $_SESSION["watchlist_info"] =
        "This auction is already in your watchlist.";

    header(
        "Location: auction-details.php?id=" .
        $auction_id
    );

    exit();

}

$stmt->close();


/* ==========================================
   ADD TO WATCHLIST
========================================== */

$stmt = $conn->prepare("
    INSERT INTO watchlist (
        user_id,
        auction_id,
        created_at
    )

    VALUES (
        ?,
        ?,
        NOW()
    )
");

$stmt->bind_param(
    "ii",
    $user_id,
    $auction_id
);


if ($stmt->execute()) {

    $_SESSION["watchlist_success"] =
        "Auction added to your watchlist successfully.";

} else {

    $_SESSION["watchlist_error"] =
        "Something went wrong. Please try again.";

}

$stmt->close();


/* ==========================================
   REDIRECT BACK
========================================== */

header(
    "Location: auction-details.php?id=" .
    $auction_id
);

exit();
?>