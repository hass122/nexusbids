<?php
/**
 * NexusBids — AI Account Takeover Detection
 * 
 * Features:
 *  - Device fingerprinting (UA + Accept-Language)
 *  - Persistent device cookie (nb_dev_token)
 *  - IP subnet tracking
 *  - Recent failed login detection
 *  - Off-hours login detection
 *  - Multi-device detection
 *  - AI-powered risk scoring (Gemini)
 *  - Rule-based fallback if AI fails
 *  - Admin alerts dashboard
 *
 * Usage:
 *   require_once __DIR__ . '/ai-security.php';
 *   $result = nb_security_handle_login($conn, $user_id);
 *   // $result = ['action' => 'allow'|'verify'|'block', 'score' => 0-100, ...]
 */

require_once __DIR__ . '/ai-chatbot.php';

/* ============================================================
   Table check
   ============================================================ */
if (!function_exists('nb_security_tables_exist')) {
    function nb_security_tables_exist(mysqli $conn): array
    {
        static $cache = null;
        if ($cache !== null)
            return $cache;

        $cache = [];
        foreach (['login_devices', 'security_alerts', 'login_activity'] as $t) {
            try {
                $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'");
                $cache[$t] = $r && $r->num_rows > 0;
            } catch (Throwable $e) {
                $cache[$t] = false;
            }
        }
        return $cache;
    }
}

/* ============================================================
   Client IP (proxy-aware)
   ============================================================ */
if (!function_exists('nb_security_client_ip')) {
    function nb_security_client_ip(): string
    {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

/* ============================================================
   IP subnet (first 2 octets)
   ============================================================ */
if (!function_exists('nb_security_ip_subnet')) {
    function nb_security_ip_subnet(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return ($parts[0] ?? '') . '.' . ($parts[1] ?? '') . '.0.0';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 4)) . '::';
        }
        return $ip;
    }
}

/* ============================================================
   Device hash (UA + Accept-Language)
   ============================================================ */
if (!function_exists('nb_security_device_hash')) {
    function nb_security_device_hash(): string
    {
        $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        return hash('sha256', $ua . '|' . $lang);
    }
}

/* ============================================================
   Record device (called after successful login)
   - Saves device fingerprint to DB
   - Sets a persistent cookie to recognize this browser
   ============================================================ */
if (!function_exists('nb_security_record_device')) {
    function nb_security_record_device(mysqli $conn, int $user_id): void
    {
        $tables = nb_security_tables_exist($conn);
        if (!($tables['login_devices'] ?? false))
            return;

        $hash   = nb_security_device_hash();
        $ip     = nb_security_client_ip();
        $subnet = nb_security_ip_subnet($ip);
        $ua     = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        try {
            $stmt = $conn->prepare("
                INSERT INTO login_devices (user_id, device_hash, user_agent, ip_address, ip_subnet)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    last_seen = NOW(),
                    login_count = login_count + 1,
                    ip_address = VALUES(ip_address),
                    ip_subnet = VALUES(ip_subnet)
            ");
            if (!$stmt)
                return;
            $stmt->bind_param("issss", $user_id, $hash, $ua, $ip, $subnet);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log("security_record_device failed: " . $e->getMessage());
        }

        /* ★ Set persistent device cookie to recognize this browser next time */
        if (empty($_COOKIE['nb_dev_token'])) {
            $token = bin2hex(random_bytes(16));
            setcookie('nb_dev_token', $token, [
                'expires'  => time() + (86400 * 365),  // 1 year
                'path'     => '/',
                'secure'   => !empty($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            // Make it available in current request too
            $_COOKIE['nb_dev_token'] = $token;
        }
    }
}

/* ============================================================
   Gather suspicious signals
   ============================================================ */
if (!function_exists('nb_security_get_signals')) {
    function nb_security_get_signals(mysqli $conn, int $user_id): array
    {
        $tables  = nb_security_tables_exist($conn);
        $signals = [];

        if (!($tables['login_devices'] ?? false))
            return $signals;

        $hash   = nb_security_device_hash();
        $ip     = nb_security_client_ip();
        $subnet = nb_security_ip_subnet($ip);

        try {
            /* 1. New device? (based on UA fingerprint) */
            $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM login_devices WHERE user_id = ? AND device_hash = ?");
            $stmt->bind_param("is", $user_id, $hash);
            $stmt->execute();
            $known_device = intval($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
            $stmt->close();
            if (!$known_device)
                $signals['new_device'] = true;

            /* 2. New IP subnet? */
            $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM login_devices WHERE user_id = ? AND ip_subnet = ?");
            $stmt->bind_param("is", $user_id, $subnet);
            $stmt->execute();
            $known_subnet = intval($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
            $stmt->close();
            if (!$known_subnet)
                $signals['new_ip_subnet'] = true;

            /* 3. Recent failed logins (last 30 min) */
            if ($tables['login_activity'] ?? false) {
                $stmt = $conn->prepare("
                    SELECT COUNT(*) AS c FROM login_activity
                    WHERE user_id = ? AND status = 'failed'
                      AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                ");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $recent_fails = intval($stmt->get_result()->fetch_assoc()['c'] ?? 0);
                $stmt->close();
                if ($recent_fails >= 3)
                    $signals['recent_failed_logins'] = $recent_fails;
            }

            /* 4. Off-hours login? (midnight - 5 AM) */
            $hour = (int) date('H');
            if ($hour >= 0 && $hour < 5)
                $signals['off_hours_login'] = true;

            /* 5. Too many distinct devices in last 24h? */
            $stmt = $conn->prepare("
                SELECT COUNT(DISTINCT device_hash) AS c FROM login_devices
                WHERE user_id = ? AND last_seen >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $devices_24h = intval($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
            if ($devices_24h >= 3)
                $signals['many_devices_24h'] = $devices_24h;

            /* ★ 6. No device cookie but user has logged in before → suspicious */
            if (empty($_COOKIE['nb_dev_token'])) {
                $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM login_devices WHERE user_id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $has_devices = intval($stmt->get_result()->fetch_assoc()['c'] ?? 0);
                $stmt->close();
                if ($has_devices > 0) {
                    $signals['no_device_cookie'] = true;
                }
            }

        } catch (Throwable $e) {
            error_log("security_get_signals failed: " . $e->getMessage());
        }

        return $signals;
    }
}

/* ============================================================
   Rule-based risk pre-score (fast path — no AI needed)
   ============================================================ */
if (!function_exists('nb_security_rule_score')) {
    function nb_security_rule_score(array $signals): int
    {
        $score = 0;
        if (!empty($signals['new_device']))
            $score += 15;
        if (!empty($signals['new_ip_subnet']))
            $score += 20;
        if (!empty($signals['recent_failed_logins']))
            $score += 25;
        if (!empty($signals['off_hours_login']))
            $score += 10;
        if (!empty($signals['many_devices_24h']))
            $score += 20;
        if (!empty($signals['no_device_cookie']))
            $score += 35;   // ★ NEW — strong signal
        return min(100, $score);
    }
}

/* ============================================================
   AI risk scoring (called only when needed)
   ============================================================ */
if (!function_exists('nb_security_ai_score')) {
    function nb_security_ai_score(array $signals, array $user_ctx): array
    {
        if (empty($signals)) {
            return ['score' => 0, 'reason' => 'No suspicious signals', 'action' => 'allow'];
        }

        $prompt  = "You are a security analyst reviewing a suspicious login attempt for an online auction marketplace.\n\n";
        $prompt .= "SIGNALS DETECTED:\n";
        foreach ($signals as $k => $v) {
            $prompt .= "- " . $k . ": " . (is_bool($v) ? 'yes' : $v) . "\n";
        }
        $prompt .= "\nUSER CONTEXT:\n";
        $prompt .= "- Account age: " . intval($user_ctx['account_days'] ?? 0) . " days\n";
        $prompt .= "- Total successful logins: " . intval($user_ctx['total_logins'] ?? 0) . "\n";
        $prompt .= "- 2FA enabled: " . (!empty($user_ctx['has_2fa']) ? 'yes' : 'no') . "\n";
        $prompt .= "- Account has pending payouts: " . (!empty($user_ctx['has_pending_payout']) ? 'yes' : 'no') . "\n";

        $prompt .= "\nTASK:\n";
        $prompt .= "Rate the account takeover risk 0-100 and choose an action.\n";
        $prompt .= "- 0-30: allow (trust the login)\n";
        $prompt .= "- 31-69: verify (require OTP / email confirmation)\n";
        $prompt .= "- 70-100: block (deny login and alert admin)\n\n";
        $prompt .= "Return ONLY valid JSON in this exact shape (no markdown, no extra text):\n";
        $prompt .= '{"score": 45, "reason": "short one-line explanation", "action": "verify"}';

        $system = "You are a security analyst. Return only valid JSON. No markdown. No extra text.";

        $result = nb_chat_send(
            [['role' => 'user', 'content' => $prompt]],
            $system
        );

        if (empty($result['ok'])) {
            /* AI failed — fall back to rule score */
            $fallback = nb_security_rule_score($signals);
            return [
                'score'  => $fallback,
                'reason' => 'AI unavailable. Rule-based score used.',
                'action' => $fallback >= 70 ? 'block' : ($fallback >= 31 ? 'verify' : 'allow'),
            ];
        }

        $raw = trim($result['text']);

        /* Strip markdown code fences if AI adds them */
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw);
        $raw = trim($raw);

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !isset($decoded['score'])) {
            $fallback = nb_security_rule_score($signals);
            return [
                'score'  => $fallback,
                'reason' => 'Could not parse AI response. Rule-based fallback used.',
                'action' => $fallback >= 70 ? 'block' : ($fallback >= 31 ? 'verify' : 'allow'),
            ];
        }

        $score  = max(0, min(100, intval($decoded['score'])));
        $reason = substr((string) ($decoded['reason'] ?? 'Suspicious activity'), 0, 500);
        $action = in_array($decoded['action'] ?? '', ['allow', 'verify', 'block'], true)
            ? $decoded['action']
            : ($score >= 70 ? 'block' : ($score >= 31 ? 'verify' : 'allow'));

        return ['score' => $score, 'reason' => $reason, 'action' => $action];
    }
}

/* ============================================================
   Create security alert
   ============================================================ */
if (!function_exists('nb_security_create_alert')) {
    function nb_security_create_alert(mysqli $conn, int $user_id, array $result, array $signals): int
    {
        $tables = nb_security_tables_exist($conn);
        if (!($tables['security_alerts'] ?? false))
            return 0;

        $score   = intval($result['score'] ?? 0);
        $action  = (string) ($result['action'] ?? 'allow');
        $reason  = (string) ($result['reason'] ?? '');
        $sigJson = json_encode($signals);
        $ip      = nb_security_client_ip();
        $ua      = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        try {
            $stmt = $conn->prepare("
                INSERT INTO security_alerts
                (user_id, risk_score, action_taken, signals, ai_reason, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt)
                return 0;
            $stmt->bind_param("iisssss", $user_id, $score, $action, $sigJson, $reason, $ip, $ua);
            $stmt->execute();
            $id = $stmt->insert_id;
            $stmt->close();
            return (int) $id;
        } catch (Throwable $e) {
            error_log("security_create_alert failed: " . $e->getMessage());
            return 0;
        }
    }
}

/* ============================================================
   MAIN entry — call this on every login
   ============================================================ */
if (!function_exists('nb_security_handle_login')) {
    function nb_security_handle_login(mysqli $conn, int $user_id): array
    {
        $signals = nb_security_get_signals($conn, $user_id);

        /* Fast path: no signals → allow immediately, record device */
        if (empty($signals)) {
            nb_security_record_device($conn, $user_id);
            return [
                'action'   => 'allow',
                'score'    => 0,
                'reason'   => 'No suspicious activity',
                'alert_id' => 0,
            ];
        }

        /* Rule-based quick check */
        $ruleScore = nb_security_rule_score($signals);

        /* Only call AI if rule score is non-trivial */
        if ($ruleScore < 20) {
            nb_security_record_device($conn, $user_id);
            return [
                'action'   => 'allow',
                'score'    => $ruleScore,
                'reason'   => 'Low-risk signals only',
                'alert_id' => 0,
            ];
        }

        /* Build user context for AI */
        $user_ctx = [
            'account_days'        => 0,
            'total_logins'        => 0,
            'has_2fa'             => false,
            'has_pending_payout'  => false,
        ];

        try {
            $stmt = $conn->prepare("
                SELECT
                    DATEDIFF(NOW(), created_at) AS account_days,
                    twofa_enabled
                FROM users WHERE user_id = ? LIMIT 1
            ");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $user_ctx['account_days'] = intval($row['account_days'] ?? 0);
                $user_ctx['has_2fa']      = !empty($row['twofa_enabled']);
            }

            $tables = nb_security_tables_exist($conn);
            if ($tables['login_activity'] ?? false) {
                $r = $conn->query("SELECT COUNT(*) AS c FROM login_activity WHERE user_id = $user_id AND status = 'success'");
                if ($r) {
                    $user_ctx['total_logins'] = intval($r->fetch_assoc()['c'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            // silent
        }

        /* Call AI */
        $result = nb_security_ai_score($signals, $user_ctx);

        /* Record device if allowed */
        if (($result['action'] ?? 'allow') === 'allow') {
            nb_security_record_device($conn, $user_id);
        }

        /* Always create alert (even for allow, for audit trail) when signals existed */
        $alert_id = nb_security_create_alert($conn, $user_id, $result, $signals);

        return [
            'action'   => $result['action'],
            'score'    => $result['score'],
            'reason'   => $result['reason'],
            'signals'  => $signals,
            'alert_id' => $alert_id,
        ];
    }
}