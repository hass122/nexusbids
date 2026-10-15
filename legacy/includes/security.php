<?php


if (!function_exists('nb_log_login')) {

    /* ---------------------------------------------------------
       LOGIN ACTIVITY
    --------------------------------------------------------- */
    function nb_log_login(mysqli $conn, int $user_id, string $status = 'success'): void {
        try {
            if ($conn->query("SHOW TABLES LIKE 'login_activity'")->num_rows === 0) return;
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
            $device = nb_detect_device($ua);
            $stmt = $conn->prepare("INSERT INTO login_activity (user_id, ip_address, user_agent, device, status) VALUES (?, ?, ?, ?, ?)");
            if (!$stmt) return;
            $stmt->bind_param("issss", $user_id, $ip, $ua, $device, $status);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log("nb_log_login failed: " . $e->getMessage());
        }
    }

    function nb_detect_device(string $ua): string {
        if (stripos($ua, 'Mobile') !== false || stripos($ua, 'Android') !== false || stripos($ua, 'iPhone') !== false) return 'Mobile';
        if (stripos($ua, 'Tablet') !== false || stripos($ua, 'iPad') !== false) return 'Tablet';
        if (stripos($ua, 'Windows') !== false) return 'Windows';
        if (stripos($ua, 'Mac') !== false)     return 'macOS';
        if (stripos($ua, 'Linux') !== false)   return 'Linux';
        return 'Unknown';
    }

        /* ---------------------------------------------------------
       FAILED LOGIN LOCKOUT
    --------------------------------------------------------- */
    if (!defined('NB_MAX_FAILED_LOGINS')) define('NB_MAX_FAILED_LOGINS', 5);
    if (!defined('NB_LOCKOUT_MINUTES'))   define('NB_LOCKOUT_MINUTES', 15);

    function nb_check_lockout(mysqli $conn, int $user_id): ?int {
        try {
            $r = $conn->prepare("SELECT locked_until FROM users WHERE user_id = ? LIMIT 1");
            if (!$r) return null;
            $r->bind_param("i", $user_id);
            $r->execute();
            $row = $r->get_result()->fetch_assoc();
            $r->close();
            if (!empty($row['locked_until']) && strtotime($row['locked_until']) > time()) {
                return strtotime($row['locked_until']) - time();
            }
            return null;
        } catch (Throwable $e) { return null; }
    }

    function nb_register_failed_login(mysqli $conn, int $user_id): void {
        try {
            $r = $conn->prepare("SELECT failed_logins FROM users WHERE user_id = ? LIMIT 1");
            $r->bind_param("i", $user_id);
            $r->execute();
            $cur = intval($r->get_result()->fetch_assoc()['failed_logins'] ?? 0);
            $r->close();
            $new = $cur + 1;
            if ($new >= NB_MAX_FAILED_LOGINS) {
                $lock = date('Y-m-d H:i:s', time() + NB_LOCKOUT_MINUTES * 60);
                $upd = $conn->prepare("UPDATE users SET failed_logins = ?, last_failed_login = NOW(), locked_until = ? WHERE user_id = ?");
                $upd->bind_param("isi", $new, $lock, $user_id);
                $upd->execute(); $upd->close();
            } else {
                $upd = $conn->prepare("UPDATE users SET failed_logins = ?, last_failed_login = NOW() WHERE user_id = ?");
                $upd->bind_param("ii", $new, $user_id);
                $upd->execute(); $upd->close();
            }
        } catch (Throwable $e) {}
    }

    function nb_reset_failed_logins(mysqli $conn, int $user_id): void {
        try {
            $conn->query("UPDATE users SET failed_logins = 0, locked_until = NULL WHERE user_id = " . intval($user_id));
        } catch (Throwable $e) {}
    }

    /* ---------------------------------------------------------
       2FA (TOTP - RFC 6238, 30s, 6 digits)
    --------------------------------------------------------- */
    function nb_generate_totp_secret(int $length = 20): string {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // Base32 alphabet
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $secret;
    }

    function nb_base32_decode(string $secret): string {
        $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper($secret);
        $buffer = 0; $bits = 0; $output = '';
        for ($i = 0; $i < strlen($secret); $i++) {
            $val = strpos($map, $secret[$i]);
            if ($val === false) continue;
            $buffer = ($buffer << 5) | $val;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xFF);
            }
        }
        return $output;
    }

    function nb_totp_code(string $secret, ?int $time_slice = null, int $offset = 0): string {
        $time_slice = $time_slice ?? intdiv(time(), 30);
        $time_slice += $offset;
        $bin = pack('N*', 0) . pack('N*', $time_slice);
        $key = nb_base32_decode($secret);
        $hash = hash_hmac('sha1', $bin, $key, true);
        $o = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$o]) & 0x7F) << 24)
               | ((ord($hash[$o + 1]) & 0xFF) << 16)
               | ((ord($hash[$o + 2]) & 0xFF) << 8)
               | (ord($hash[$o + 3]) & 0xFF);
        return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    function nb_verify_totp(string $secret, string $code): bool {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) return false;
        // Allow ±1 time slice drift
        for ($offset = -1; $offset <= 1; $offset++) {
            if (hash_equals(nb_totp_code($secret, null, $offset), $code)) return true;
        }
        return false;
    }

    function nb_totp_uri(string $secret, string $email, string $issuer = 'NexusBids'): string {
        $label = rawurlencode($issuer . ':' . $email);
        $params = http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => 6,
            'period'    => 30,
        ]);
        return "otpauth://totp/{$label}?{$params}";
    }

    function nb_generate_backup_codes(int $count = 8): array {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = sprintf('%04d-%04d', random_int(0, 9999), random_int(0, 9999));
        }
        return $codes;
    }

    /* ---------------------------------------------------------
       SESSION TRACKING
    --------------------------------------------------------- */
    function nb_track_session(mysqli $conn, int $user_id): void {
        try {
            if ($conn->query("SHOW TABLES LIKE 'active_sessions'")->num_rows === 0) return;
            $sid = session_id();
            if (!$sid) return;
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
            $stmt = $conn->prepare("
                INSERT INTO active_sessions (session_id, user_id, ip_address, user_agent)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE last_active = NOW()
            ");
            if (!$stmt) return;
            $stmt->bind_param("siss", $sid, $user_id, $ip, $ua);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {}
    }

    function nb_kill_session(mysqli $conn, string $session_id): void {
        try {
            if ($conn->query("SHOW TABLES LIKE 'active_sessions'")->num_rows === 0) return;
            $stmt = $conn->prepare("DELETE FROM active_sessions WHERE session_id = ?");
            $stmt->bind_param("s", $session_id);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {}
    }

    /* ---------------------------------------------------------
       PASSWORD STRENGTH
    --------------------------------------------------------- */
    function nb_password_strength(string $pw): array {
        $score = 0;
        $issues = [];
        if (strlen($pw) >= 8)  $score += 25; else $issues[] = 'At least 8 characters';
        if (strlen($pw) >= 12) $score += 15;
        if (preg_match('/[A-Z]/', $pw)) $score += 15; else $issues[] = 'Add uppercase letter';
        if (preg_match('/[a-z]/', $pw)) $score += 15; else $issues[] = 'Add lowercase letter';
        if (preg_match('/[0-9]/', $pw)) $score += 15; else $issues[] = 'Add a number';
        if (preg_match('/[^A-Za-z0-9]/', $pw)) $score += 15; else $issues[] = 'Add a symbol';

        $label = 'Weak';
        if ($score >= 80) $label = 'Strong';
        elseif ($score >= 60) $label = 'Good';
        elseif ($score >= 40) $label = 'Fair';

        return ['score' => min(100, $score), 'label' => $label, 'issues' => $issues];
    }
}