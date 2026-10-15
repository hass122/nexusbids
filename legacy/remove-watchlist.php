<?php

session_start();

require_once "config/database.php";


/* ==========================================
   CHECK USER LOGIN
========================================== */

if (!isset($_SESSION["user_id"])) {

    header("Location: auth/login.php");
    exit();

}


$user_id = intval($_SESSION["user_id"]);


/* ==========================================
   GET WATCHLIST ID
========================================== */

$watchlist_id = isset($_POST["watchlist_id"])
    ? intval($_POST["watchlist_id"])
    : 0;


if ($watchlist_id <= 0) {

    header("Location: user/watchlist.php");
    exit();

}


/* ==========================================
   DELETE WATCHLIST ITEM
========================================== */

$stmt = $conn->prepare("
    DELETE FROM watchlist
    WHERE watchlist_id = ?
    AND user_id = ?
");

$stmt->bind_param(
    "ii",
    $watchlist_id,
    $user_id
);


if ($stmt->execute()) {

    $_SESSION["watchlist_success"] =
        "Auction removed from your watchlist.";

} else {

    $_SESSION["watchlist_error"] =
        "Unable to remove the auction.";

}

$stmt->close();


/* ==========================================
   REDIRECT
========================================== */

header(
    "Location: user/watchlist.php"
);

exit();

?>