<?php
/**
 * NexusBids — AI Shill Bidding Detection
 * Detects fake bids placed by sellers or their associates.
 */

require_once __DIR__ . '/ai-chatbot.php';

/* ============================================================
   Config
   ============================================================ */
if (!function_exists('nb_bidfraud_config')) {
    function nb_bidfraud_config(): array
    {
        return [
            'min_score_to_alert' => (int) (getenv('BIDFRAUD_MIN_SCORE') ?: 40),
            'last_minute_seconds'=> (int) (getenv('BIDFRAUD_LAST_MIN') ?: 60),
        ];
    }
}

/* ============================================================
   Client IP helper (reuse pattern from ai-security)
   ============================================================ */
if (!function_exists('nb_bidfraud_client_ip')) {
    function nb_bidfraud_client_ip(): string
    {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

if (!function_exists('nb_bidfraud_subnet')) {
    function nb_bidfraud_subnet(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $ip);
            return ($p[0] ?? '') . '.' . ($p[1] ?? '') . '.0.0';
        }
        return $ip;
    }
}

if (!function_exists('nb_bidfraud_device_hash')) {
    function nb_bidfraud_device_hash(): string
    {
        $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        return hash('sha256', $ua . '|' . $lang);
    }
}

/* ============================================================
   Gather signals for a specific bid
   ============================================================ */
if (!function_exists('nb_bidfraud_signals')) {
    function nb_bidfraud_signals(mysqli $conn, int $auction_id, int $bidder_id): array
    {
        $signals = [];
        $bidder_ip     = nb_bidfraud_client_ip();
        $bidder_subnet = nb_bidfraud_subnet($bidder_ip);
        $bidder_device = nb_bidfraud_device_hash();

        try {
            /* --- Auction + seller --- */
            $stmt = $conn->prepare("
                SELECT seller_id, starting_price, current_bid, end_time, created_at, title
                FROM auctions WHERE auction_id = ? LIMIT 1
            ");
            $stmt->bind_param("i", $auction_id);
            $stmt->execute();
            $auc = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$auc) return [];

            $seller_id = (int) $auc['seller_id'];

            /* --- Bidder info --- */
            $stmt = $conn->prepare("SELECT created_at AS registered_at FROM users WHERE user_id = ? LIMIT 1");
            $stmt->bind_param("i", $bidder_id);
            $stmt->execute();
            $b = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $bidder_registered = $b['registered_at'] ?? null;

            /* Signal 1: Bidder == seller */
            if ($bidder_id === $seller_id) {
                $signals['self_bidding'] = true;
            }

            /* Signal 2: Bidder registered very recently (last 24h) */
            if ($bidder_registered) {
                $hours_old = (time() - strtotime($bidder_registered)) / 3600;
                if ($hours_old < 24) {
                    $signals['new_account'] = round($hours_old, 1) . 'h old';
                }
            }

            /* Signal 3: Bidder's IP subnet matches seller's known logins */
            $has_login_devices = $conn->query("SHOW TABLES LIKE 'login_devices'")->num_rows > 0;
            if ($has_login_devices) {
                $stmt = $conn->prepare("
                    SELECT COUNT(*) AS c FROM login_devices
                    WHERE user_id = ? AND ip_subnet = ?
                ");
                $stmt->bind_param("is", $seller_id, $bidder_subnet);
                $stmt->execute();
                $seller_shares_subnet = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
                $stmt->close();
                if ($seller_shares_subnet) {
                    $signals['same_subnet_as_seller'] = $bidder_subnet;
                }
            }

            /* Signal 4: Same device hash as seller */
            if ($has_login_devices && $bidder_device) {
                $stmt = $conn->prepare("
                    SELECT COUNT(*) AS c FROM login_devices
                    WHERE user_id = ? AND device_hash = ?
                ");
                $stmt->bind_param("is", $seller_id, $bidder_device);
                $stmt->execute();
                $same_device = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
                $stmt->close();
                if ($same_device) {
                    $signals['same_device_as_seller'] = true;
                }
            }

            /* Signal 5: Multiple bidders on this auction from same subnet */
            $stmt = $conn->prepare("
                SELECT COUNT(DISTINCT bidder_id) AS c FROM bids
                WHERE auction_id = ? AND ip_address LIKE ?
            ");
            $subnet_like = substr($bidder_subnet, 0, strrpos($bidder_subnet, '.') + 1) . '%';
            $stmt->bind_param("is", $auction_id, $subnet_like);
            $stmt->execute();
            $same_subnet_bidders = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
            if ($same_subnet_bidders >= 2) {
                $signals['multiple_bidders_same_subnet'] = $same_subnet_bidders;
            }

            /* Signal 6: Bidder only bids on this seller's auctions */
            $stmt = $conn->prepare("
                SELECT
                    COUNT(DISTINCT a.seller_id) AS sellers_bid_on,
                    COUNT(*) AS total_bids
                FROM bids b
                INNER JOIN auctions a ON b.auction_id = a.auction_id
                WHERE b.bidder_id = ?
            ");
            $stmt->bind_param("i", $bidder_id);
            $stmt->execute();
            $stats = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ((int) $stats['sellers_bid_on'] === 1 && (int) $stats['total_bids'] >= 3) {
                $signals['bids_only_this_seller'] = (int) $stats['total_bids'] . ' bids';
            }

            /* Signal 7: Last-minute bid (within 60 sec of end) */
            $secs_left = strtotime($auc['end_time']) - time();
            if ($secs_left > 0 && $secs_left <= 60) {
                $signals['last_minute_bid'] = $secs_left . 's left';
            }

            /* Signal 8: Bidder's bids mostly on same seller (>80%) */
            $stmt = $conn->prepare("
                SELECT
                    SUM(CASE WHEN a.seller_id = ? THEN 1 ELSE 0 END) AS same_seller_bids,
                    COUNT(*) AS total_bids
                FROM bids b
                INNER JOIN auctions a ON b.auction_id = a.auction_id
                WHERE b.bidder_id = ?
            ");
            $stmt->bind_param("ii", $seller_id, $bidder_id);
            $stmt->execute();
            $pct = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $total = (int) ($pct['total_bids'] ?? 0);
            $same  = (int) ($pct['same_seller_bids'] ?? 0);
            if ($total >= 5 && ($same / $total) >= 0.8) {
                $signals['mostly_same_seller'] = round(($same / $total) * 100) . '%';
            }

        } catch (Throwable $e) {
            error_log("bidfraud_signals failed: " . $e->getMessage());
        }

        return $signals;
    }
}

/* ============================================================
   Rule-based score
   ============================================================ */
if (!function_exists('nb_bidfraud_rule_score')) {
    function nb_bidfraud_rule_score(array $signals): int
    {
        $score = 0;
        if (!empty($signals['self_bidding']))                  $score += 100; // auto-fraud
        if (!empty($signals['same_device_as_seller']))         $score += 60;
        if (!empty($signals['same_subnet_as_seller']))         $score += 45;
        if (!empty($signals['bids_only_this_seller']))         $score += 30;
        if (!empty($signals['multiple_bidders_same_subnet']))  $score += 25;
        if (!empty($signals['mostly_same_seller']))            $score += 20;
        if (!empty($signals['new_account']))                   $score += 15;
        if (!empty($signals['last_minute_bid']))               $score += 10;
        return min(100, $score);
    }
}

/* ============================================================
   AI scoring (only when needed)
   ============================================================ */
if (!function_exists('nb_bidfraud_ai_score')) {
    function nb_bidfraud_ai_score(array $signals, array $ctx): array
    {
        if (empty($signals)) {
            return ['score' => 0, 'reason' => 'No suspicious signals', 'recommendation' => 'clear'];
        }

        $prompt  = "You are a fraud analyst reviewing a bid on an online auction for shill bidding.\n\n";
        $prompt .= "SIGNALS DETECTED:\n";
        foreach ($signals as $k => $v) {
            $prompt .= "- " . $k . ": " . (is_bool($v) ? 'yes' : $v) . "\n";
        }
        $prompt .= "\nCONTEXT:\n";
        $prompt .= "- Auction: " . ($ctx['auction_title'] ?? '') . "\n";
        $prompt .= "- Current bid: Rs. " . intval($ctx['current_bid'] ?? 0) . "\n";
        $prompt .= "- Total bids on this auction: " . intval($ctx['total_bids'] ?? 0) . "\n";
        $prompt .= "- Total unique bidders: " . intval($ctx['unique_bidders'] ?? 0) . "\n";

        $prompt .= "\nTASK:\n";
        $prompt .= "Rate shill-bidding risk 0-100 and choose a recommendation.\n";
        $prompt .= "- 0-30: clear (likely genuine)\n";
        $prompt .= "- 31-69: review (admin should look)\n";
        $prompt .= "- 70-100: high_risk (likely shill, flag prominently)\n\n";
        $prompt .= "Return ONLY valid JSON (no markdown):\n";
        $prompt .= '{"score": 45, "recommendation": "review", "reason": "one-line explanation"}';

        $system = "You are a fraud analyst. Return only valid JSON.";

        $res = nb_chat_send([['role' => 'user', 'content' => $prompt]], $system);

        if (empty($res['ok'])) {
            $fallback = nb_bidfraud_rule_score($signals);
            return [
                'score' => $fallback,
                'reason' => 'AI unavailable. Rule-based score used.',
                'recommendation' => $fallback >= 70 ? 'high_risk' : ($fallback >= 31 ? 'review' : 'clear'),
            ];
        }

        $raw = trim($res['text']);
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw);
        $decoded = json_decode(trim($raw), true);

        if (!is_array($decoded) || !isset($decoded['score'])) {
            $fallback = nb_bidfraud_rule_score($signals);
            return [
                'score' => $fallback,
                'reason' => 'AI parse failed. Rule-based score used.',
                'recommendation' => $fallback >= 70 ? 'high_risk' : ($fallback >= 31 ? 'review' : 'clear'),
            ];
        }

        $score = max(0, min(100, (int) $decoded['score']));
        $rec   = in_array($decoded['recommendation'] ?? '', ['clear','review','high_risk'], true)
                 ? $decoded['recommendation']
                 : ($score >= 70 ? 'high_risk' : ($score >= 31 ? 'review' : 'clear'));

        return [
            'score' => $score,
            'reason' => substr((string) ($decoded['reason'] ?? ''), 0, 500),
            'recommendation' => $rec,
        ];
    }
}

/* ============================================================
   MAIN entry — call after a bid is placed
   Returns: ['alert_id' => int, 'risk_score' => int, 'recommendation' => string]
   ============================================================ */
if (!function_exists('nb_bidfraud_check')) {
    function nb_bidfraud_check(mysqli $conn, int $auction_id, int $bid_id, int $bidder_id): array
    {
        $cfg = nb_bidfraud_config();

        /* Store bid's IP + device on the bid row */
        try {
            $ip = nb_bidfraud_client_ip();
            $dev = nb_bidfraud_device_hash();
            $upd = $conn->prepare("UPDATE bids SET ip_address = ?, device_hash = ? WHERE bid_id = ?");
            $upd->bind_param("ssi", $ip, $dev, $bid_id);
            $upd->execute();
            $upd->close();
        } catch (Throwable $e) {}

        $signals = nb_bidfraud_signals($conn, $auction_id, $bidder_id);
        if (empty($signals)) {
            return ['alert_id' => 0, 'risk_score' => 0, 'recommendation' => 'clear'];
        }

        $ruleScore = nb_bidfraud_rule_score($signals);

        /* Fast path: rule score high enough and includes self_bidding → skip AI */
        if (!empty($signals['self_bidding'])) {
            $result = ['score' => 100, 'recommendation' => 'high_risk', 'reason' => 'Seller placed a bid on their own auction.'];
        } elseif ($ruleScore >= 80) {
            $result = ['score' => $ruleScore, 'recommendation' => 'high_risk', 'reason' => 'Multiple high-risk signals detected.'];
        } elseif ($ruleScore < 20) {
            /* Low risk — no alert */
            return ['alert_id' => 0, 'risk_score' => $ruleScore, 'recommendation' => 'clear'];
        } else {
            /* Build context for AI */
            $ctx = ['auction_title' => '', 'current_bid' => 0, 'total_bids' => 0, 'unique_bidders' => 0];
            try {
                $stmt = $conn->prepare("SELECT title, current_bid FROM auctions WHERE auction_id = ? LIMIT 1");
                $stmt->bind_param("i", $auction_id);
                $stmt->execute();
                $a = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($a) { $ctx['auction_title'] = $a['title']; $ctx['current_bid'] = $a['current_bid']; }

                $r = $conn->query("SELECT COUNT(*) AS t, COUNT(DISTINCT bidder_id) AS u FROM bids WHERE auction_id = $auction_id")->fetch_assoc();
                if ($r) { $ctx['total_bids'] = (int) $r['t']; $ctx['unique_bidders'] = (int) $r['u']; }
            } catch (Throwable $e) {}

            $result = nb_bidfraud_ai_score($signals, $ctx);
        }

        if (($result['score'] ?? 0) < $cfg['min_score_to_alert']) {
            return ['alert_id' => 0, 'risk_score' => $result['score'], 'recommendation' => $result['recommendation']];
        }

        /* Find seller_id for the alert */
        $seller_id = 0;
        try {
            $stmt = $conn->prepare("SELECT seller_id FROM auctions WHERE auction_id = ? LIMIT 1");
            $stmt->bind_param("i", $auction_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $seller_id = (int) ($row['seller_id'] ?? 0);
        } catch (Throwable $e) {}

        /* Insert alert */
        $alert_id = 0;
        try {
            $sigJson = json_encode($signals);
            $reason  = $result['reason'] ?? '';
            $score   = (int) $result['score'];

            $stmt = $conn->prepare("
                INSERT INTO bid_fraud_alerts
                (auction_id, seller_id, bid_id, bidder_id, risk_score, signals, ai_reason)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iiiiiss", $auction_id, $seller_id, $bid_id, $bidder_id, $score, $sigJson, $reason);
            $stmt->execute();
            $alert_id = $stmt->insert_id;
            $stmt->close();
        } catch (Throwable $e) {
            error_log("bidfraud insert alert failed: " . $e->getMessage());
        }

        return [
            'alert_id'       => $alert_id,
            'risk_score'     => (int) ($result['score'] ?? 0),
            'recommendation' => $result['recommendation'] ?? 'review',
        ];
    }
}