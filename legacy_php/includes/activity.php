<?php

if (!function_exists('nb_log_activity')) {
    function nb_log_activity(mysqli $conn, int $seller_id, string $action, string $description = ''): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $conn->prepare(
            "INSERT INTO seller_activity_log (seller_id, action, description, ip_address)
             VALUES (?, ?, ?, ?)"
        );
        if (!$stmt) return;
        $stmt->bind_param("isss", $seller_id, $action, $description, $ip);
        @$stmt->execute();
        $stmt->close();
    }
}