<?php

function nb_log_admin_action($conn, $admin_id, $action, $details = null)
{
    $stmt = $conn->prepare("
        INSERT INTO admin_activity_log (admin_id, action, details)
        VALUES (?, ?, ?)
    ");

    $stmt->bind_param("iss", $admin_id, $action, $details);
    $stmt->execute();
}
