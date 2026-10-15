<?php
/**
 * NexusBids AI Engine — OpenAI-compatible API
 * Works with: OpenRouter, NVIDIA NIM, Groq, any OpenAI-compatible provider
 *
 * Features:
 *  - Strong language enforcement (English / Urdu / Roman Urdu)
 *  - Reasoning-model support (ling, deepseek-r1, etc.)
 *  - Retry + auto-fallback on 429/503
 *  - Vision support via nb_chat_vision()
 */

/* ============================================================
   .env Loader
   ============================================================ */
if (!function_exists('nb_env_load')) {
    function nb_env_load(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        $file = __DIR__ . '/../.env';
        if (!file_exists($file)) return;

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;

            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\n\r\0\x0B\"'");

            $existing = getenv($k);
            if ($existing === false || $existing === '') {
                putenv("$k=$v");
                $_ENV[$k] = $v;
            }
        }
    }
}
nb_env_load();

/* ============================================================
   Config
   ============================================================ */
if (!function_exists('nb_chat_config')) {
    function nb_chat_config(): array
    {
        return [
            'provider'       => 'openai-compatible',
            'api_key'        => getenv('AI_API_KEY') ?: '',
            'base_url'       => rtrim(getenv('AI_BASE_URL') ?: 'https://openrouter.ai/api/v1', '/'),
            'model'          => getenv('AI_MODEL') ?: 'cohere/north-mini-code:free',
            'fallback_model' => getenv('AI_FALLBACK_MODEL') ?: 'google/gemma-4-26b-a4b-it:free',
            'site_url'       => getenv('AI_SITE_URL') ?: 'http://localhost',
            'site_name'      => getenv('AI_SITE_NAME') ?: 'NexusBids',
            'max_per_5min'   => (int) (getenv('CHAT_MAX_PER_5MIN') ?: 20),
            'max_msg_len'    => (int) (getenv('CHAT_MAX_MSG_LENGTH') ?: 500),
            'timeout'        => (int) (getenv('CHAT_TIMEOUT') ?: 60),
            'max_retries'    => (int) (getenv('CHAT_MAX_RETRIES') ?: 3),
            'temperature'    => (float) (getenv('CHAT_TEMPERATURE') ?: 0.5),
            'max_tokens'     => (int) (getenv('CHAT_MAX_TOKENS') ?: 2000),
        ];
    }
}

/* ============================================================
   Rate Limit (DB-backed)
   ============================================================ */
if (!function_exists('nb_chat_rate_ok')) {
    function nb_chat_rate_ok(mysqli $conn, string $key, int $max = 20, int $window = 300): bool
    {
        $now = date('Y-m-d H:i:s');

        $stmt = $conn->prepare("SELECT rate_id, hit_count, window_start FROM ai_chat_rate WHERE rate_key = ? LIMIT 1");
        if (!$stmt) return true;

        $stmt->bind_param("s", $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $ins = $conn->prepare("INSERT INTO ai_chat_rate (rate_key, hit_count, window_start) VALUES (?, 1, ?)");
            if ($ins) {
                $ins->bind_param("ss", $key, $now);
                $ins->execute();
                $ins->close();
            }
            return true;
        }

        $start = strtotime($row['window_start']);
        if (time() - $start > $window) {
            $upd = $conn->prepare("UPDATE ai_chat_rate SET hit_count = 1, window_start = ? WHERE rate_id = ?");
            if ($upd) {
                $upd->bind_param("si", $now, $row['rate_id']);
                $upd->execute();
                $upd->close();
            }
            return true;
        }

        if ((int) $row['hit_count'] >= $max) return false;

        $upd = $conn->prepare("UPDATE ai_chat_rate SET hit_count = hit_count + 1 WHERE rate_id = ?");
        if ($upd) {
            $upd->bind_param("i", $row['rate_id']);
            $upd->execute();
            $upd->close();
        }
        return true;
    }
}

/* ============================================================
   System Prompt — STRONG language enforcement
   ============================================================ */
if (!function_exists('nb_chat_system_prompt')) {
    function nb_chat_system_prompt(array $ctx = []): string
    {
        $name     = $ctx['name'] ?? 'Guest';
        $role     = $ctx['role'] ?? 'visitor';
        $loggedIn = !empty($ctx['user_id']);
        $unread   = (int) ($ctx['unread'] ?? 0);

        $base = "You are 'NexusBot', the friendly AI assistant for NexusBids — Pakistan's online auction marketplace.

⚠️ CRITICAL LANGUAGE RULE (MUST FOLLOW — HIGHEST PRIORITY):
- Detect the language of the user's LAST message and reply in THAT language ONLY.
- User wrote in ENGLISH (e.g. 'Hello', 'How do I bid?') → reply in ENGLISH ONLY.
- User wrote in ROMAN URDU (Urdu in English letters, e.g. 'kaise hain', 'bid lagana hai') → reply in ROMAN URDU ONLY.
- User wrote in URDU SCRIPT (اردو) → reply in URDU SCRIPT ONLY.
- NEVER default to Urdu just because the platform is Pakistani.
- NEVER mix languages in one reply.
- If the language is unclear or mixed, DEFAULT TO ENGLISH.

CORE RULES:
- Keep answers short, clear, and helpful (max 4 sentences unless asked for detail).
- Never invent prices, auction IDs, or user data.
- If asked something you don't know, guide them to the right page or support (support@nexusbids.pk).

WHAT YOU CAN HELP WITH:
- How to register, login, and verify an account
- How to browse, search, and filter auctions
- How to place a bid, set auto-bid / proxy bid, and how increments work
- What happens when an auction ends and how winners are decided
- Payment methods (1-Link, Raast, JazzCash, Easypaisa, Bank transfer)
- Order status, shipment tracking, and delivery timelines
- Watchlist, notifications, reviews, and seller ratings
- Common issues: bid not accepted, payment not verified, forgot password

KEY URLS TO SHARE WHEN RELEVANT:
- /auctions.php — browse auctions
- /categories.php — categories
- /about.php — how it works, safety
- /auth/register.php — create account
- /user/dashboard.php — buyer dashboard
- /seller/dashboard.php — seller dashboard
- /notifications.php — notifications

TONE: Warm, professional, concise. Use light emojis occasionally (👍 🎯 ✅) — never overdo it.
";

        if ($loggedIn) {
            $base .= "\nCURRENT USER CONTEXT:\n- Name: {$name}\n- Role: {$role}\n- Unread notifications: {$unread}\n";
        } else {
            $base .= "\nCURRENT VISITOR: Not logged in. If they want to bid/watchlist/pay, kindly suggest logging in or registering.\n";
        }

        return $base;
    }
}

/* ============================================================
   Database Helpers (chat history)
   ============================================================ */
if (!function_exists('nb_chat_save')) {
    function nb_chat_save(mysqli $conn, string $sid, ?int $uid, string $role, string $content): void
    {
        $stmt = $conn->prepare("INSERT INTO ai_chat_messages (session_id, user_id, role, content) VALUES (?, ?, ?, ?)");
        if (!$stmt) return;

        $uidVal = ($uid !== null && $uid > 0) ? $uid : null;
        $stmt->bind_param("siss", $sid, $uidVal, $role, $content);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('nb_chat_history')) {
    function nb_chat_history(mysqli $conn, string $sid, int $limit = 20): array
    {
        $limit = max(1, min(50, (int) $limit));

        $stmt = $conn->prepare("
            SELECT role, content
            FROM ai_chat_messages
            WHERE session_id = ?
            ORDER BY message_id DESC
            LIMIT $limit
        ");
        if (!$stmt) return [];

        $stmt->bind_param("s", $sid);
        $stmt->execute();
        $res = $stmt->get_result();

        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();

        return array_reverse($rows);
    }
}

if (!function_exists('nb_chat_clear')) {
    function nb_chat_clear(mysqli $conn, string $sid): void
    {
        $stmt = $conn->prepare("DELETE FROM ai_chat_messages WHERE session_id = ?");
        if (!$stmt) return;

        $stmt->bind_param("s", $sid);
        $stmt->execute();
        $stmt->close();
    }
}

/* ============================================================
   Language Detection (server-side reinforcement)
   ============================================================ */
if (!function_exists('nb_chat_detect_language')) {
    function nb_chat_detect_language(string $text): string
    {
        $text = trim($text);
        if ($text === '') return 'unknown';

        /* Urdu script (Arabic/Urdu Unicode range) */
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
            return 'urdu';
        }

        /* Roman Urdu detection */
        $lower = ' ' . strtolower($text) . ' ';
        $romanUrduWords = [
            ' hai ', ' hain ', ' kaise ', ' kya ', ' kyun ', ' kahan ',
            ' kaun ', ' mera ', ' meri ', ' mujhe ', ' aap ', ' aapko ',
            ' kar ', ' karo ', ' karna ', ' karein ', ' kare ', ' hota ',
            ' hoti ', ' lagana ', ' laga ', ' chahiye ', ' milega ',
            ' milegi ', ' dena ', ' dijye ', ' batao ', ' bata ', ' samajh ',
            ' sakta ', ' sakti ', ' nahi ', ' nahin ', ' koi ', ' kuch ',
            ' lekin ', ' magar ', ' phir ', ' abhi ', ' yahan ', ' wahan ',
            ' bhai ', ' yaar ', ' theek ', ' acha ', ' accha ', ' bohot ',
            ' bahut ', ' zyada ', ' kam ', ' jaldi ', ' der ',
        ];

        $hits = 0;
        foreach ($romanUrduWords as $w) {
            if (strpos($lower, $w) !== false) $hits++;
        }
        if ($hits >= 1) return 'roman_urdu';

        /* Latin script → English */
        if (preg_match('/[a-zA-Z]/', $text)) return 'english';

        return 'unknown';
    }
}

/* ============================================================
   HTTP Helper (IPv4 forced)
   ============================================================ */
if (!function_exists('nb_chat_http')) {
    function nb_chat_http(string $url, array $headers, array $body, int $timeout = 60): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'cURL init failed.', 'code' => 0];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'error' => "Network error: $err", 'code' => 0];
        }

        $json = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            $msg = $json['error']['message']
                ?? $json['error']
                ?? $json['message']
                ?? "HTTP $code";
            if (is_array($msg)) $msg = json_encode($msg);
            return [
                'ok'    => false,
                'error' => $msg,
                'code'  => $code,
                'raw'   => $raw,
            ];
        }

        return ['ok' => true, 'data' => $json, 'code' => $code];
    }
}

/* ============================================================
   Response parser — handles content, reasoning, multi-part
   ============================================================ */
if (!function_exists('nb_chat_extract_text')) {
    function nb_chat_extract_text(array $data): array
    {
        $msg = $data['choices'][0]['message'] ?? [];

        $text = '';

        /* Primary: content field */
        if (!empty($msg['content'])) {
            $content = $msg['content'];
            if (is_array($content)) {
                foreach ($content as $part) {
                    if (isset($part['text'])) $text .= $part['text'];
                }
            } else {
                $text = (string) $content;
            }
        }

        /* Fallback: reasoning field (reasoning models: ling-3.0, deepseek-r1, etc.) */
        if (trim($text) === '' && !empty($msg['reasoning'])) {
            $reasoning = $msg['reasoning'];
            if (is_array($reasoning)) {
                $text = implode('', array_column($reasoning, 'text'));
            } else {
                $text = (string) $reasoning;
            }
            /* Strip leading reasoning markers like "1. **Analyze...**" */
            $text = preg_replace('/^\s*(\d+\.\s*)?\*\*.*?\*\*\s*/s', '', $text);
        }

        return [
            'text'   => trim($text),
            'finish' => $data['choices'][0]['finish_reason'] ?? null,
        ];
    }
}

/* ============================================================
   OpenAI-compatible chat call
   ============================================================ */
if (!function_exists('nb_chat_openai_call')) {
    function nb_chat_openai_call(string $model, array $messages, string $system): array
    {
        $cfg = nb_chat_config();

        if (empty($cfg['api_key'])) {
            return ['ok' => false, 'error' => 'AI API key not configured.', 'code' => 0];
        }

        /* Build OpenAI-style message list */
        $oaiMessages = [['role' => 'system', 'content' => $system]];

        foreach ($messages as $m) {
            $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $text = (string) ($m['content'] ?? '');
            if ($text === '') continue;
            $oaiMessages[] = ['role' => $role, 'content' => $text];
        }

        if (count($oaiMessages) < 2) {
            return ['ok' => false, 'error' => 'No valid message content.', 'code' => 0];
        }

        /* ★ Language reinforcement — final system hint */
        $lastUserText = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $lastUserText = (string) ($messages[$i]['content'] ?? '');
                break;
            }
        }

        if ($lastUserText !== '') {
            $detected = nb_chat_detect_language($lastUserText);
            $langReminder = match ($detected) {
                'english'    => "The user's last message is in ENGLISH. Reply in ENGLISH ONLY.",
                'roman_urdu' => "The user's last message is in ROMAN URDU. Reply in ROMAN URDU ONLY.",
                'urdu'       => "The user's last message is in URDU (اردو script). Reply in URDU SCRIPT ONLY.",
                default      => "Reply in English.",
            };
            $oaiMessages[] = ['role' => 'system', 'content' => $langReminder];
        }

        $payload = [
            'model'       => $model,
            'messages'    => $oaiMessages,
            'temperature' => $cfg['temperature'],
            'max_tokens'  => $cfg['max_tokens'],
        ];

        $url = $cfg['base_url'] . '/chat/completions';

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['api_key'],
            'HTTP-Referer: ' . $cfg['site_url'],
            'X-Title: ' . $cfg['site_name'],
        ];

        $res = nb_chat_http($url, $headers, $payload, $cfg['timeout']);

        if (!$res['ok']) {
            return [
                'ok'    => false,
                'error' => $res['error'] ?? 'AI request failed.',
                'code'  => $res['code'] ?? 0,
            ];
        }

        $data = $res['data'] ?? [];

        /* ★ Extract text using helper */
        $parsed = nb_chat_extract_text($data);
        $text   = $parsed['text'];
        $finish = $parsed['finish'];

        if ($text === '') {
            if ($finish === 'content_filter') {
                return ['ok' => false, 'error' => 'Reply blocked by content filter.', 'code' => 200];
            }
            if ($finish === 'length') {
                return ['ok' => false, 'error' => 'AI response too long. Try a shorter question.', 'code' => 200];
            }
            return ['ok' => false, 'error' => 'Empty response from AI.', 'code' => 200];
        }

        return ['ok' => true, 'text' => $text, 'code' => 200];
    }
}

/* ============================================================
   Main entry — retry + fallback
   ============================================================ */
if (!function_exists('nb_chat_gemini')) {
    function nb_chat_gemini(array $messages, string $system): array
    {
        $cfg      = nb_chat_config();
        $primary  = $cfg['model'];
        $fallback = $cfg['fallback_model'];
        $maxTries = max(1, (int) $cfg['max_retries']);

        $models = [$primary];
        if ($fallback && $fallback !== $primary) {
            $models[] = $fallback;
        }

        $lastError = null;

        foreach ($models as $modelIndex => $model) {
            for ($attempt = 1; $attempt <= $maxTries; $attempt++) {

                $res = nb_chat_openai_call($model, $messages, $system);

                if (!empty($res['ok'])) {
                    return $res;
                }

                $code   = $res['code'] ?? 0;
                $errMsg = $res['error'] ?? '';

                /* ---- 401 / 403 — auth error ---- */
                if (in_array($code, [401, 403], true)) {
                    return [
                        'ok'    => false,
                        'error' => 'AI authentication failed. Check your API key in .env',
                        'code'  => $code,
                    ];
                }

                /* ---- 429 rate limit ---- */
                if ($code === 429) {
                    $lastError = $res;
                    if ($modelIndex === 0 && $fallback && $fallback !== $primary) {
                        break;
                    }
                    if ($attempt < $maxTries) {
                        sleep(3 * $attempt);
                        continue;
                    }
                    break;
                }

                /* ---- 503 high demand ---- */
                if ($code === 503) {
                    $lastError = $res;
                    if ($attempt < $maxTries) {
                        usleep(1500000 * $attempt);
                        continue;
                    }
                    break;
                }

                /* ---- 400 / 404 → try fallback ---- */
                if (in_array($code, [400, 404], true)) {
                    $lastError = $res;
                    break;
                }

                /* ---- Other errors → retry once ---- */
                $lastError = $res;
                if ($attempt < 2) {
                    usleep(1000000);
                    continue;
                }
                break;
            }
        }

        if ($lastError) {
            $msg  = $lastError['error'] ?? 'AI service unavailable.';
            $code = $lastError['code'] ?? 0;

            if ($code === 429) {
                $msg = 'AI is temporarily busy. Please wait a moment and try again.';
            } elseif ($code === 503) {
                $msg = 'AI is experiencing high demand. Please try again in 30 seconds.';
            } elseif (in_array($code, [400, 404], true)) {
                $msg = 'AI model configuration error. Please check .env';
            }

            $lastError['error'] = $msg;
            return $lastError;
        }

        return ['ok' => false, 'error' => 'AI service unavailable.', 'code' => 0];
    }
}

/* ============================================================
   Unified Send
   ============================================================ */
if (!function_exists('nb_chat_send')) {
    function nb_chat_send(array $messages, string $system): array
    {
        return nb_chat_gemini($messages, $system);
    }
}

/* ============================================================
   Vision helper (KYC) — OpenAI-compatible
   ============================================================ */
if (!function_exists('nb_chat_vision')) {
    function nb_chat_vision(array $images, string $prompt, string $system): array
    {
        $cfg = nb_chat_config();

        if (empty($cfg['api_key'])) {
            return ['ok' => false, 'error' => 'AI API key not configured.', 'code' => 0];
        }

        $content = [['type' => 'text', 'text' => $prompt]];

        foreach ($images as $img) {
            $mime = $img['mime'] ?? 'image/jpeg';
            $data = $img['base64'] ?? '';
            if ($data === '') continue;

            $content[] = [
                'type'      => 'image_url',
                'image_url' => ['url' => "data:{$mime};base64,{$data}"],
            ];
        }

        $payload = [
            'model'       => $cfg['model'],
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $content],
            ],
            'temperature' => 0.2,
            'max_tokens'  => $cfg['max_tokens'],
        ];

        $url = $cfg['base_url'] . '/chat/completions';

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['api_key'],
            'HTTP-Referer: ' . $cfg['site_url'],
            'X-Title: ' . $cfg['site_name'],
        ];

        $res = nb_chat_http($url, $headers, $payload, 90);

        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?? 'AI vision request failed.', 'code' => $res['code'] ?? 0];
        }

        $data = $res['data'] ?? [];
        $parsed = nb_chat_extract_text($data);
        $text = $parsed['text'];

        if ($text === '') {
            return ['ok' => false, 'error' => 'Empty vision response.', 'code' => 200];
        }

        return ['ok' => true, 'text' => $text, 'code' => 200];
    }
}
/* ============================================================
   Direct model call (no retry) — used by ai-generate-description.php
   ============================================================ */
if (!function_exists('nb_chat_call_model')) {
    function nb_chat_call_model(string $model, array $messages, string $system): array
    {
        return nb_chat_openai_call($model, $messages, $system);
    }
}