<?php
require_once "config/database.php";
require_once "includes/process-auctions.php";

// Set header for JSON response
header('Content-Type: application/json');

// Run the auction lifecycle engine
$success = processEndedAuctions($conn);

echo json_encode([
    'status' => $success ? 'success' : 'error',
    'timestamp' => date('Y-m-d H:i:s')
]);
?>