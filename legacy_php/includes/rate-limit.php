<?php

/*
|--------------------------------------------------------------------------
| Simple Rate Limiting
|--------------------------------------------------------------------------
| Session-based throttle for actions that don't have their own table
| (bid submissions, registration). For login we use the users table's
| failed_attempts/locked_until columns instead, since that survives
| across sessions/devices.
|--------------------------------------------------------------------------
*/

function nb_throttle($key, $max_attempts = 5, $window_seconds = 60)
{
    $bucket_key = "throttle_" . $key;

    if (empty($_SESSION[$bucket_key])) {
        $_SESSION[$bucket_key] = [];
    }

    $now = time();

    // Drop attempts outside the window
    $_SESSION[$bucket_key] = array_values(array_filter(
        $_SESSION[$bucket_key],
        function ($timestamp) use ($now, $window_seconds) {
            return ($now - $timestamp) < $window_seconds;
        }
    ));

    if (count($_SESSION[$bucket_key]) >= $max_attempts) {
        return false; // blocked
    }

    $_SESSION[$bucket_key][] = $now;

    return true; // allowed
}


/*
|--------------------------------------------------------------------------
| Account lockout (DB-backed) — used by auth/login_process.php
|--------------------------------------------------------------------------
*/

function nb_is_locked($user)
{
    return !empty($user["locked_until"])
        && strtotime($user["locked_until"]) > time();
}

function nb_register_failed_login($conn, $user_id, $current_failed_attempts)
{
    $failed = intval($current_failed_attempts) + 1;
    $locked_until = null;

    if ($failed >= 5) {
        $locked_until = date("Y-m-d H:i:s", time() + (15 * 60)); // 15 min lock
    }

    $stmt = $conn->prepare("
        UPDATE users
        SET failed_attempts = ?, locked_until = ?
        WHERE user_id = ?
    ");

    $stmt->bind_param("isi", $failed, $locked_until, $user_id);
    $stmt->execute();
}

function nb_reset_failed_login($conn, $user_id)
{
    $stmt = $conn->prepare("
        UPDATE users
        SET failed_attempts = 0, locked_until = NULL
        WHERE user_id = ?
    ");

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
}
