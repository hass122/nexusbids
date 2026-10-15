<?php

/*
|--------------------------------------------------------------------------
| Notification Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('nb_notify')) {

    function nb_notify($conn, $user_id, $type, $title, $message, $link = null)
    {
        // Detect if the `link` column exists
        static $has_link = null;
        if ($has_link === null) {
            $res = $conn->query("SHOW COLUMNS FROM notifications LIKE 'link'");
            $has_link = ($res && $res->num_rows > 0);
        }

        if ($has_link) {
            $stmt = $conn->prepare("
                INSERT INTO notifications
                    (user_id, type, title, message, link, is_read, created_at)
                VALUES (?, ?, ?, ?, ?, 0, NOW())
            ");
            if (!$stmt) {
                error_log("nb_notify prepare failed: " . $conn->error);
                return false;
            }
            $stmt->bind_param("issss", $user_id, $type, $title, $message, $link);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO notifications
                    (user_id, type, title, message, is_read, created_at)
                VALUES (?, ?, ?, ?, 0, NOW())
            ");
            if (!$stmt) {
                error_log("nb_notify prepare failed: " . $conn->error);
                return false;
            }
            $stmt->bind_param("isss", $user_id, $type, $title, $message);
        }

        if (!$stmt->execute()) {
            error_log("nb_notify execute failed: " . $stmt->error);
            $stmt->close();
            return false;
        }

        $stmt->close();
        return true;
    }
}

if (!function_exists('nb_unread_count')) {

    function nb_unread_count($conn, $user_id)
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM notifications
            WHERE user_id = ? AND is_read = 0
        ");
        if (!$stmt)
            return 0;
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return intval($row["total"] ?? 0);
    }
}

?>