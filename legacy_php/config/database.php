<?php

// Every auction timing feature (countdowns, start/end checks, login
// lockouts) depends on PHP's date/time functions and MySQL's NOW()
// agreeing on what "now" is. Without an explicit timezone, PHP falls
// back to whatever the server happens to be configured with, which
// can silently drift from what sellers actually pick in the
// datetime-local auction form. Pin it explicitly.
date_default_timezone_set("Asia/Karachi");

$host = "localhost";
$username = "root";
$password = "";
$database = "nexus_bids";

$conn = new mysqli(
    $host,
    $username,
    $password,
    $database
);

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// Make sure MySQL's NOW()/CURRENT_TIMESTAMP agrees with the PHP
// timezone above, so "end_time <= NOW()" checks in the auction
// processing logic line up with what the countdown timer shows.
$conn->query("SET time_zone = '+05:00'");
?>