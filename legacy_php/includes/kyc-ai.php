<?php
/**
 * NexusBids — KYC AI Engine
 * Uses Gemini Vision for OCR + Face Match + Data Validation
 * 
 * Flow: OCR CNIC → Face Match → Data Validation → Score → Recommendation
 * 
 * @version 2.0
 * @package NexusBids\KYC
 */

require_once __DIR__ . '/kyc-helpers.php';

if (!defined('KYC_AI_MODEL')) {
    define('KYC_AI_MODEL', 'gemini-1.5-flash');
}

/* =========================================================
   MAIN ORCHESTRATOR
========================================================= */
function nb_kyc_ai_process(mysqli $conn, int $kyc_id): array
{
    // Lock row — status = processing
    $conn->query("UPDATE kyc_verifications SET ai_status = 'processing' WHERE kyc_id = " . (int)$kyc_id);

    try {
        /* ---------- 1. Load KYC record ---------- */
        $stmt = $conn->prepare("
            SELECT k.*, u.full_name AS user_full_name
            FROM kyc_verifications k
            INNER JOIN users u ON u.user_id = k.user_id
            WHERE k.kyc_id = ? LIMIT 1
        ");
        if (!$stmt) throw new RuntimeException('Prepare failed (load kyc): ' . $conn->error);
        $stmt->bind_param("i", $kyc_id);
        $stmt->execute();
        $kyc = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$kyc) throw new RuntimeException('KYC not found');

        /* ---------- 2. Resolve file paths ---------- */
        $front_path  = KYC_UPLOAD_DIR . '/' . $kyc['cnic_front_path'];
        $selfie_path = KYC_UPLOAD_DIR . '/' . $kyc['selfie_path'];

        if (!is_file($front_path))  throw new RuntimeException('CNIC front image missing');
        if (!is_file($selfie_path)) throw new RuntimeException('Selfie image missing');

        /* ---------- 3. OCR CNIC Front ---------- */
        $ocr = nb_kyc_ai_ocr($front_path);
        $ocr_ok = !empty($ocr['ok']);
        $ocr_data = $ocr_ok ? $ocr['data'] : [];
        $ocr_quality = $ocr_ok ? (int)($ocr['quality'] ?? 70) : 0;

        /* ---------- 4. Face Match (Selfie ↔ CNIC) ---------- */
        $face = nb_kyc_ai_face_match($selfie_path, $front_path);
        $face_ok = !empty($face['ok']);
        $face_score = $face_ok ? (int)($face['confidence'] ?? 0) : 0;

        /* ---------- 5. Data Validation (submitted vs extracted) ---------- */
        $data_match = nb_kyc_ai_data_match($ocr_data, $kyc);

        /* ---------- 6. Calculate overall score ---------- */
        $score = nb_kyc_ai_calculate_score([
            'ocr_quality' => $ocr_quality,
            'face_match'  => $face_score,
            'data_match'  => $data_match,
        ]);

        /* ---------- 7. Determine recommendation ---------- */
        $recommendation = nb_kyc_ai_recommendation($score);

        /* ---------- 8. Collect flags ---------- */
        $flags = [];
        if (!$ocr_ok) $flags[] = 'ocr_failed';
        if ($ocr_quality < 50) $flags[] = 'poor_image_quality';
        if (!$face_ok) $flags[] = 'face_match_failed';
        if ($face_score < 60) $flags[] = 'low_face_match';
        if ($data_match < 60) $flags[] = 'data_mismatch';

        /* ---------- 9. Prepare values for DB ---------- */
        $extracted_json = json_encode($ocr_data, JSON_UNESCAPED_UNICODE);
        $flags_json = json_encode($flags, JSON_UNESCAPED_UNICODE);
        $notes = nb_kyc_ai_build_notes($ocr_data, $face, $data_match, $score);

        // ★ CRITICAL FIX: Constants CANNOT be passed by reference in bind_param()
        // We must assign to a variable first
        $ai_model_value = KYC_AI_MODEL;

        /* ---------- 10. Save to DB ---------- */
        $upd = $conn->prepare("
            UPDATE kyc_verifications 
            SET ai_status = 'completed',
                ai_confidence = ?,
                ai_recommendation = ?,
                ai_face_match = ?,
                ai_ocr_quality = ?,
                ai_data_match = ?,
                ai_extracted_data = ?,
                ai_flags = ?,
                ai_notes = ?,
                ai_processed_at = NOW(),
                ai_model = ?
            WHERE kyc_id = ?
        ");
        if (!$upd) throw new RuntimeException('Prepare failed (update ai): ' . $conn->error);

        // ★ Type string: i-s-i-i-i-s-s-s-s-i
        //   $score(i), $recommendation(s), $face_score(i), $ocr_quality(i), $data_match(i),
        //   $extracted_json(s), $flags_json(s), $notes(s), $ai_model_value(s), $kyc_id(i)
        $upd->bind_param(
            "isiiissssi",
            $score,
            $recommendation,
            $face_score,
            $ocr_quality,
            $data_match,
            $extracted_json,
            $flags_json,
            $notes,
            $ai_model_value,
            $kyc_id
        );

        if (!$upd->execute()) {
            throw new RuntimeException('Execute failed (update ai): ' . $upd->error);
        }
        $upd->close();

        return [
            'ok' => true,
            'score' => $score,
            'recommendation' => $recommendation,
            'face_match' => $face_score,
            'ocr_quality' => $ocr_quality,
            'data_match' => $data_match,
            'flags' => $flags,
        ];

    } catch (Throwable $e) {
        error_log("KYC AI process failed (kyc_id=$kyc_id): " . $e->getMessage());

        $err_msg = $conn->real_escape_string($e->getMessage());
        $conn->query("UPDATE kyc_verifications SET ai_status = 'failed', ai_notes = '$err_msg' WHERE kyc_id = " . (int)$kyc_id);

        return [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }
}

/* =========================================================
   GEMINI API CALL
========================================================= */
function nb_kyc_ai_gemini_call(array $parts, int $timeout = 30): array
{
    $cfg = function_exists('nb_chat_config') ? nb_chat_config() : [];
    $api_key = $cfg['gemini_key'] ?? getenv('GEMINI_API_KEY') ?: '';

    if ($api_key === '') {
        return ['ok' => false, 'error' => 'Gemini API key not configured'];
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . KYC_AI_MODEL . ':generateContent?key=' . urlencode($api_key);

    $payload = [
        'contents' => [['parts' => $parts]],
        'generationConfig' => [
            'temperature' => 0.1,
            'maxOutputTokens' => 1024,
            'responseMimeType' => 'application/json',
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['ok' => false, 'error' => 'Network: ' . $curl_error];
    }
    if ($http_code !== 200) {
        return ['ok' => false, 'error' => "HTTP $http_code: " . substr($response, 0, 200)];
    }

    $decoded = json_decode($response, true);
    $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';

    if ($text === '') {
        return ['ok' => false, 'error' => 'Empty AI response'];
    }

    return ['ok' => true, 'text' => $text];
}

/* =========================================================
   OCR — Extract CNIC data
========================================================= */
function nb_kyc_ai_ocr(string $image_path): array
{
    if (!is_file($image_path)) return ['ok' => false, 'error' => 'File missing', 'quality' => 0];

    $base64 = base64_encode(file_get_contents($image_path));
    $mime = mime_content_type($image_path);

    $prompt = "Analyze this Pakistani CNIC (National ID card) image. Extract all text and return ONLY valid JSON:

{
  \"name\": \"full name in English\",
  \"cnic_number\": \"format 12345-1234567-1\",
  \"father_name\": \"father's name\",
  \"date_of_birth\": \"YYYY-MM-DD\",
  \"address\": \"complete address\",
  \"expiry_date\": \"YYYY-MM-DD\",
  \"quality_score\": 0-100,
  \"notes\": \"any observations about image quality\"
}

Rules:
- If text is Urdu, transliterate to English
- If a field is unreadable, use empty string
- quality_score: how clear is the image? (0=poor, 100=perfect)
- ONLY return the JSON, no markdown, no explanation";

    $result = nb_kyc_ai_gemini_call([
        ['text' => $prompt],
        ['inline_data' => ['mime_type' => $mime, 'data' => $base64]],
    ]);

    if (!$result['ok']) {
        return ['ok' => false, 'error' => $result['error'], 'quality' => 0];
    }

    $data = nb_kyc_ai_parse_json($result['text']);
    if (!$data) {
        return ['ok' => false, 'error' => 'Invalid JSON from OCR', 'quality' => 0];
    }

    return [
        'ok' => true,
        'data' => $data,
        'quality' => (int) ($data['quality_score'] ?? 60),
    ];
}

/* =========================================================
   FACE MATCH — Compare selfie with CNIC photo
========================================================= */
function nb_kyc_ai_face_match(string $selfie_path, string $cnic_path): array
{
    if (!is_file($selfie_path) || !is_file($cnic_path)) {
        return ['ok' => false, 'error' => 'Files missing'];
    }

    $selfie_b64 = base64_encode(file_get_contents($selfie_path));
    $selfie_mime = mime_content_type($selfie_path);
    $cnic_b64 = base64_encode(file_get_contents($cnic_path));
    $cnic_mime = mime_content_type($cnic_path);

    $prompt = "Compare the face in IMAGE 1 (selfie) with the face in IMAGE 2 (CNIC card). 

Return ONLY valid JSON:
{
  \"same_person\": true or false,
  \"confidence\": 0-100,
  \"notes\": \"brief observation\"
}

Confidence scoring:
- 90-100: Faces clearly match (same person, good lighting)
- 70-89: Very likely same person
- 50-69: Uncertain (blurry, angle issue)
- 0-49: Different person or unreadable

Be honest. Only return JSON.";

    $result = nb_kyc_ai_gemini_call([
        ['text' => $prompt],
        ['inline_data' => ['mime_type' => $selfie_mime, 'data' => $selfie_b64]],
        ['inline_data' => ['mime_type' => $cnic_mime, 'data' => $cnic_b64]],
    ], 45);

    if (!$result['ok']) {
        return ['ok' => false, 'error' => $result['error']];
    }

    $data = nb_kyc_ai_parse_json($result['text']);
    if (!$data) {
        return ['ok' => false, 'error' => 'Invalid JSON'];
    }

    return [
        'ok' => true,
        'same_person' => !empty($data['same_person']),
        'confidence' => (int) ($data['confidence'] ?? 0),
        'notes' => $data['notes'] ?? '',
    ];
}

/* =========================================================
   DATA MATCH — Compare submitted vs AI-extracted
========================================================= */
function nb_kyc_ai_data_match(array $extracted, array $kyc): int
{
    if (empty($extracted)) return 0;

    $scores = [];

    /* Name match (fuzzy) */
    $submitted_name = strtolower(trim($kyc['full_name_on_cnic'] ?? ''));
    $extracted_name = strtolower(trim($extracted['name'] ?? ''));
    if ($submitted_name !== '' && $extracted_name !== '') {
        similar_text($submitted_name, $extracted_name, $pct);
        $scores[] = $pct;
    }

    /* CNIC number match (exact after normalization) */
    $sub_cnic = preg_replace('/\D/', '', $kyc['cnic_number_masked'] ?? '');
    $ext_cnic = preg_replace('/\D/', '', $extracted['cnic_number'] ?? '');
    if (strlen($sub_cnic) === 13 && strlen($ext_cnic) === 13) {
        $scores[] = ($sub_cnic === $ext_cnic) ? 100 : 0;
    }

    /* DOB match */
    $sub_dob = $kyc['date_of_birth'] ?? '';
    $ext_dob = $extracted['date_of_birth'] ?? '';
    if ($sub_dob !== '' && $ext_dob !== '') {
        $scores[] = ($sub_dob === $ext_dob) ? 100 : 30;
    }

    if (empty($scores)) return 0;
    return (int) round(array_sum($scores) / count($scores));
}

/* =========================================================
   SCORE CALCULATION
========================================================= */
function nb_kyc_ai_calculate_score(array $results): int
{
    // Weights: OCR 20%, Face 45%, Data 35%
    $score =
        ($results['ocr_quality'] * 0.20) +
        ($results['face_match']  * 0.45) +
        ($results['data_match']  * 0.35);

    return (int) round($score);
}

function nb_kyc_ai_recommendation(int $score): string
{
    if ($score >= 75) return 'approve';
    if ($score >= 50) return 'review';
    return 'reject';
}

/* =========================================================
   HELPERS
========================================================= */
function nb_kyc_ai_parse_json(string $text): ?array
{
    $text = trim($text);
    // Remove markdown code fences
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);

    $decoded = json_decode($text, true);
    if (is_array($decoded)) return $decoded;

    // Try to extract JSON from response
    if (preg_match('/\{.*\}/s', $text, $m)) {
        $decoded = json_decode($m[0], true);
        if (is_array($decoded)) return $decoded;
    }

    return null;
}

function nb_kyc_ai_build_notes(array $ocr, array $face, int $data_match, int $score): string
{
    $notes = [];
    $notes[] = "AI Score: $score/100";

    if (!empty($ocr['name'])) $notes[] = "Extracted name: {$ocr['name']}";
    if (!empty($ocr['cnic_number'])) $notes[] = "CNIC: {$ocr['cnic_number']}";
    if (!empty($face['confidence'])) $notes[] = "Face match: {$face['confidence']}%";
    $notes[] = "Data match: {$data_match}%";

    return implode(' · ', $notes);
}