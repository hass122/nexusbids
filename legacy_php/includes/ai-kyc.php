<?php
/**
 * NexusBids — AI KYC Document Verification
 * Uses Gemini Vision API for CNIC analysis.
 *
 * Requires: includes/ai-chatbot.php (for shared helpers)
 */

require_once __DIR__ . '/ai-chatbot.php';

/* ============================================================
   Table check
   ============================================================ */
if (!function_exists('nb_kyc_table_exists')) {
    function nb_kyc_table_exists(mysqli $conn): bool
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        try {
            $r = $conn->query("SHOW TABLES LIKE 'kyc_verifications'");
            $cache = $r && $r->num_rows > 0;
        } catch (Throwable $e) {
            $cache = false;
        }
        return $cache;
    }
}

/* ============================================================
   Config helper
   ============================================================ */
if (!function_exists('nb_kyc_config')) {
    function nb_kyc_config(): array
    {
        return [
            'api_key' => getenv('GEMINI_API_KEY') ?: '',
            'model'   => getenv('GEMINI_VISION_MODEL') ?: 'gemini-1.5-flash',
            'timeout' => 90,
        ];
    }
}

/* ============================================================
   Read image as base64
   ============================================================ */
if (!function_exists('nb_kyc_image_to_base64')) {
    function nb_kyc_image_to_base64(string $path): ?array
    {
        if (!file_exists($path)) return null;

        $mime = mime_content_type($path);
        $allowed = [
            'image/jpeg' => 'image/jpeg',
            'image/jpg'  => 'image/jpeg',
            'image/png'  => 'image/png',
            'image/webp' => 'image/webp',
        ];
        if (!isset($allowed[$mime])) return null;

        $data = file_get_contents($path);
        if ($data === false) return null;

        /* 4MB cap to keep request sane */
        if (strlen($data) > 4 * 1024 * 1024) return null;

        return [
            'mime'   => $allowed[$mime],
            'base64' => base64_encode($data),
        ];
    }
}

/* ============================================================
   Call Gemini Vision API — generateContent endpoint
   ============================================================ */
if (!function_exists('nb_kyc_gemini_vision')) {
    function nb_kyc_gemini_vision(array $images, string $prompt, string $system): array
    {
        $cfg = nb_kyc_config();

        if (empty($cfg['api_key'])) {
            return ['ok' => false, 'error' => 'Gemini API key not configured. Add GEMINI_API_KEY to .env', 'code' => 0];
        }

        /* Build parts: text prompt + images */
        $parts = [['text' => $prompt]];

        foreach ($images as $img) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $img['mime'],
                    'data'      => $img['base64'],
                ],
            ];
        }

        /* Gemini generateContent payload */
        $payload = [
            'contents' => [[
                'role'  => 'user',
                'parts' => $parts,
            ]],
            'systemInstruction' => [
                'parts' => [['text' => $system]],
            ],
            'generationConfig' => [
                'temperature'      => 0.2,
                'maxOutputTokens'  => 1500,
                'responseMimeType' => 'application/json',
            ],
        ];

        /* Gemini endpoint — model in URL path, key in header */
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
             . rawurlencode($cfg['model'])
             . ':generateContent';

        $headers = [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $cfg['api_key'],
        ];

        /* Reuse shared HTTP helper from ai-chatbot.php */
        $res = nb_chat_http($url, $headers, $payload, $cfg['timeout']);

        if (empty($res['ok'])) {
            return [
                'ok'    => false,
                'error' => $res['error'] ?? 'Gemini vision request failed.',
                'code'  => $res['code'] ?? 0,
            ];
        }

        $data = $res['data'] ?? [];

        /* Extract text from Gemini response */
        $text = '';
        if (!empty($data['candidates'][0]['content']['parts'])) {
            foreach ($data['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['text'])) $text .= $part['text'];
            }
        }

        $text = trim($text);

        if ($text === '') {
            $finish = $data['candidates'][0]['finishReason'] ?? null;
            if ($finish === 'SAFETY') {
                return ['ok' => false, 'error' => 'Image blocked by safety filter.', 'code' => 200];
            }
            if ($finish === 'MAX_TOKENS') {
                return ['ok' => false, 'error' => 'Response cut off. Try a clearer image.', 'code' => 200];
            }
            return ['ok' => false, 'error' => 'Empty response from Gemini.', 'code' => 200];
        }

        return ['ok' => true, 'text' => $text, 'code' => 200];
    }
}

/* ============================================================
   Analyze CNIC document
   ============================================================ */
if (!function_exists('nb_kyc_analyze_document')) {
    function nb_kyc_analyze_document(string $front_path, ?string $back_path, array $user_data): array
    {
        $front = nb_kyc_image_to_base64($front_path);
        if (!$front) {
            return ['ok' => false, 'error' => 'Could not read front image. File missing or too large.'];
        }

        $images = [$front];
        if ($back_path && file_exists($back_path)) {
            $back = nb_kyc_image_to_base64($back_path);
            if ($back) $images[] = $back;
        }

        $expected_name = $user_data['full_name'] ?? '';
        $expected_id   = $user_data['id_number'] ?? '';

        $prompt  = "Analyze this Pakistani CNIC (National Identity Card) document for KYC verification.\n\n";
        $prompt .= "USER-PROVIDED INFO:\n";
        $prompt .= "- Name: {$expected_name}\n";
        $prompt .= "- CNIC Number: {$expected_id}\n\n";

        $prompt .= "Analyze the image(s) and return ONLY a JSON object with this exact shape:\n";
        $prompt .= "{\n";
        $prompt .= '  "is_document_clear": true/false,' . "\n";
        $prompt .= '  "is_valid_cnic_format": true/false,' . "\n";
        $prompt .= '  "extracted_name": "name on document or null",' . "\n";
        $prompt .= '  "extracted_cnic": "12345-1234567-1 or null",' . "\n";
        $prompt .= '  "name_matches_user": true/false,' . "\n";
        $prompt .= '  "cnic_matches_user": true/false,' . "\n";
        $prompt .= '  "is_expired": true/false,' . "\n";
        $prompt .= '  "looks_tampered": true/false,' . "\n";
        $prompt .= '  "confidence": 0-100,' . "\n";
        $prompt .= '  "issues": ["list of issues found, empty array if none"],' . "\n";
        $prompt .= '  "recommendation": "verified" | "manual_review" | "rejected",' . "\n";
        $prompt .= '  "reason": "one-line summary in English"' . "\n";
        $prompt .= "}\n\n";

        $prompt .= "RULES:\n";
        $prompt .= "- confidence = how sure you are of your assessment (0-100)\n";
        $prompt .= "- recommendation:\n";
        $prompt .= "  * verified: clear image, valid format, name+CNIC match, not expired, not tampered → confidence >= 80\n";
        $prompt .= "  * manual_review: some issues but not conclusively fake → confidence 40-79\n";
        $prompt .= "  * rejected: clearly fake/expired/tampered/not a CNIC → confidence any\n";
        $prompt .= "- Name matching: allow minor spelling variations, but flag major differences\n";
        $prompt .= "- CNIC format for Pakistan: XXXXX-XXXXXXX-X (13 digits with dashes)\n";
        $prompt .= "- Be strict but fair. When in doubt, use manual_review (not verified, not rejected)\n";
        $prompt .= "- Return ONLY the JSON, no markdown, no extra text";

        $system = "You are a KYC verification specialist for a Pakistani online marketplace. "
                . "You analyze CNIC documents carefully. Return only valid JSON.";

        $res = nb_kyc_gemini_vision($images, $prompt, $system);

        if (empty($res['ok'])) {
            return [
                'ok'    => false,
                'error' => $res['error'] ?? 'AI vision analysis failed.',
                'code'  => $res['code'] ?? 0,
            ];
        }

        /* Strip markdown fences if any */
        $raw = trim($res['text']);
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw);
        $raw = trim($raw);

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [
                'ok'    => false,
                'error' => 'Could not parse AI response as JSON.',
                'raw'   => $raw,
            ];
        }

        /* Normalise booleans */
        foreach (['is_document_clear','is_valid_cnic_format','name_matches_user','cnic_matches_user','is_expired','looks_tampered'] as $k) {
            if (isset($decoded[$k])) {
                $decoded[$k] = filter_var($decoded[$k], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            }
        }

        if (isset($decoded['confidence'])) {
            $decoded['confidence'] = max(0, min(100, (int) $decoded['confidence']));
        }

        if (empty($decoded['issues']) || !is_array($decoded['issues'])) {
            $decoded['issues'] = [];
        }

        if (empty($decoded['recommendation']) ||
            !in_array($decoded['recommendation'], ['verified','manual_review','rejected'], true)) {
            $decoded['recommendation'] = 'manual_review';
        }

        return ['ok' => true, 'data' => $decoded];
    }
}

/* ============================================================
   Save verification record + update user
   ============================================================ */
if (!function_exists('nb_kyc_save_verification')) {
    function nb_kyc_save_verification(mysqli $conn, int $user_id, array $input, array $ai_result): int
    {
        if (!nb_kyc_table_exists($conn)) return 0;

        $id_number = $input['id_number'] ?? '';
        $front     = $input['front_image'] ?? null;
        $back      = $input['back_image'] ?? null;

        $status = $ai_result['recommendation'] ?? 'manual_review';
        if (!in_array($status, ['verified','manual_review','rejected'], true)) {
            $status = 'manual_review';
        }

        $db_status = match ($status) {
            'verified' => 'verified',
            'rejected' => 'rejected',
            default    => 'manual_review',
        };

        $confidence = intval($ai_result['confidence'] ?? 0);
        $name       = $ai_result['extracted_name'] ?? null;
        $cnic       = $ai_result['extracted_cnic'] ?? null;
        $expiry     = $ai_result['ai_expiry_date'] ?? null;
        $reason     = $ai_result['reason'] ?? '';
        $signals    = json_encode($ai_result);

        try {
            $stmt = $conn->prepare("
                INSERT INTO kyc_verifications
                (user_id, id_number, id_type, front_image, back_image,
                 ai_status, ai_confidence, ai_extracted_name, ai_extracted_id,
                 ai_expiry_date, ai_reason, ai_signals)
                VALUES (?, ?, 'cnic', ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt) return 0;

            $stmt->bind_param(
                "issssisssss",
                $user_id, $id_number, $front, $back,
                $db_status, $confidence, $name, $cnic,
                $expiry, $reason, $signals
            );
            $stmt->execute();
            $id = $stmt->insert_id;
            $stmt->close();

            /* Update user record */
            $user_status = match ($db_status) {
                'verified' => 'verified',
                'rejected' => 'rejected',
                default    => 'pending',
            };

            $upd = $conn->prepare("
                UPDATE users
                SET kyc_status = ?, kyc_id_number = ?, kyc_id_image = ?, kyc_submitted_at = NOW()
                WHERE user_id = ?
            ");
            $upd->bind_param("sssi", $user_status, $id_number, $front, $user_id);
            $upd->execute();
            $upd->close();

            return (int) $id;
        } catch (Throwable $e) {
            error_log("kyc_save_verification failed: " . $e->getMessage());
            return 0;
        }
    }
}

/* ============================================================
   MAIN entry — analyze + save
   ============================================================ */
if (!function_exists('nb_kyc_verify')) {
    function nb_kyc_verify(mysqli $conn, int $user_id, string $front_path, ?string $back_path, array $user_data): array
    {
        $ai = nb_kyc_analyze_document($front_path, $back_path, $user_data);

        if (empty($ai['ok'])) {
            return [
                'ok'     => false,
                'error'  => $ai['error'] ?? 'AI analysis failed.',
                'status' => 'manual_review',
            ];
        }

        $result = $ai['data'];

        $verification_id = nb_kyc_save_verification($conn, $user_id, [
            'id_number'   => $user_data['id_number'] ?? '',
            'front_image' => basename($front_path),
            'back_image'  => $back_path ? basename($back_path) : null,
        ], $result);

        return [
            'ok'              => true,
            'verification_id' => $verification_id,
            'status'          => $result['recommendation'] ?? 'manual_review',
            'confidence'      => intval($result['confidence'] ?? 0),
            'reason'          => $result['reason'] ?? '',
            'details'         => $result,
        ];
    }
}