<?php
/**
 * NexusBids — AI Auto Description Generator
 * Endpoint: seller/ai-generate-description.php
 *
 * FIX: Never call AI if seller provided no details.
 *      Never invent condition, accessories, delivery, etc.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'seller') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please log in as a seller.']);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/ai-chatbot.php';

$seller_id = intval($_SESSION['user_id']);

/* ---------- Parse body ---------- */
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = $_POST;

/* ---------- CSRF ---------- */
$token = $input['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Security check failed. Please refresh the page.']);
    exit;
}

/* ---------- Inputs ---------- */
$title     = trim((string) ($input['title']     ?? ''));
$category  = trim((string) ($input['category']  ?? ''));
$condition = trim((string) ($input['condition'] ?? ''));
$features  = trim((string) ($input['features']  ?? ''));

if ($title === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Pehle auction title likhein.']);
    exit;
}
$title     = mb_substr($title, 0, 120);
$features  = mb_substr($features, 0, 1500);
$condition = mb_substr($condition, 0, 200);

/* ---------- Rate limit: 10/hour ---------- */
$rate_key = 'ai_desc_rate_' . $seller_id;
$now      = time();
$window   = 3600;
$max      = 10;

if (!isset($_SESSION[$rate_key]) || !is_array($_SESSION[$rate_key])) {
    $_SESSION[$rate_key] = ['count' => 0, 'start' => $now];
}
if ($now - $_SESSION[$rate_key]['start'] > $window) {
    $_SESSION[$rate_key] = ['count' => 0, 'start' => $now];
}
if ($_SESSION[$rate_key]['count'] >= $max) {
    $wait = $window - ($now - $_SESSION[$rate_key]['start']);
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error'   => 'AI limit reached (10 per hour). Try again in ' . ceil($wait / 60) . ' min.',
    ]);
    exit;
}

/* ============================================================
   ★ CRITICAL PRE-CHECK
   If seller gave NO real details → don't call AI at all.
   Return a minimal, 100% factual description instead.
   ============================================================ */
$has_real_details = (
    $features !== ''
    || $condition !== ''
    || $category !== ''
);

if (!$has_real_details) {
    /* No details at all → minimal safe description */
    $_SESSION[$rate_key]['count']++; // still count it so UI feels consistent

    $safe = "Selling my {$title}. Serious bidders only.";

    echo json_encode([
        'success'     => true,
        'description' => $safe,
        'remaining'   => max(0, $max - $_SESSION[$rate_key]['count']),
        'mode'        => 'minimal',
    ]);
    exit;
}

/* If ONLY category is given (no features, no condition), that's not enough for AI to write anything useful */
if ($features === '' && $condition === '' && $category !== '') {
    $_SESSION[$rate_key]['count']++;

    $safe = "Selling my {$title} ({$category}). Serious bidders only.";

    echo json_encode([
        'success'     => true,
        'description' => $safe,
        'remaining'   => max(0, $max - $_SESSION[$rate_key]['count']),
        'mode'        => 'minimal',
    ]);
    exit;
}

/* ---------- From here on: seller gave real details → use AI ---------- */

$_SESSION[$rate_key]['count']++;

/* ============================================================
   SYSTEM PROMPT — strict, honest, no invention
   ============================================================ */
$system = "You are a real Pakistani seller writing a short, honest auction listing on NexusBids.

STYLE: Talk like a normal person. Not like a brand or marketing team.

STRICT CONTENT RULES:
1. Use ONLY the details provided. If a detail is NOT given, DO NOT mention it. DO NOT invent it.
2. NEVER invent: condition, battery health, box, accessories, warranty, delivery method, city, courier, colour, storage, or any other spec.
3. Do NOT explain what the product IS. The buyer already knows.
4. NEVER write placeholder text like '[Seller: please add...]'.
5. NEVER narrate your thinking. NEVER echo these instructions.
6. Plain text only. No headings. No markdown. No bold.
7. End with exactly: Serious bidders only
8. Output ONLY the final description text. Nothing else.

BANNED WORDS: premium, sophisticated, elegant, refined, fantastic, opportunity, elevate, seamless, cutting-edge, state-of-the-art, exceptional, outstanding, remarkable, experience, latest, reliable, brand, familiar, convenient, full spec, ready for use, well maintained, barely used (unless seller said so).";

/* ============================================================
   USER PROMPT — item info only
   ============================================================ */
$userLines = [];
$userLines[] = "Item title: {$title}";
if ($category !== '')  $userLines[] = "Category: {$category}";
if ($condition !== '') $userLines[] = "Condition: {$condition}";
if ($features !== '')  $userLines[] = "Seller's notes: {$features}";

$userPrompt = implode("\n", $userLines);
$userPrompt .= "\n\nWrite the description now. Use ONLY the info above. Output ONLY the description text.";

$messages = [
    ['role' => 'user', 'content' => $userPrompt],
];

/* ---------- Call AI ---------- */
$result = nb_chat_send($messages, $system);

if (empty($result['ok'])) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $result['error'] ?? 'AI service is unavailable right now. Please try again.',
    ]);
    exit;
}

$description = trim((string) ($result['text'] ?? ''));

/* ============================================================
   CLEANUP — strip reasoning, placeholders, markdown
   ============================================================ */
if ($description !== '') {

    /* Reasoning prefixes */
    $reasoningPatterns = [
        '/^We need to[^\n]*\n+/is',
        '/^We must[^\n]*\n+/is',
        '/^We can[^\n]*\n+/is',
        '/^The user wants[^\n]*\n+/is',
        '/^The seller[^\n]*\n+/is',
        '/^Let me[^\n]*\n+/is',
        '/^I need to[^\n]*\n+/is',
        '/^I will[^\n]*\n+/is',
        '/^Okay[,.]?[^\n]*\n+/is',
        '/^Alright[,.]?[^\n]*\n+/is',
        '/^So we[^\n]*\n+/is',
        '/^First[,:]?[^\n]*\n+/is',
        '/^Here is[^\n]*:\s*\n+/is',
        '/^Here\'s[^\n]*:\s*\n+/is',
        '/^Task:[^\n]*\n+/is',
        '/^Requirements?:[^\n]*\n+/is',
        '/^Format:[^\n]*\n+/is',
        '/^Output:[^\n]*\n+/is',
    ];
    foreach ($reasoningPatterns as $p) {
        $description = preg_replace($p, '', $description);
    }

    /* If still starts with reasoning, extract first real paragraph */
    if (preg_match('/^(we |i |the seller|the user|let me|so we|first[,:]|output)/i', trim($description))) {
        if (preg_match('/(?:^|\n\n)(- .*?)$/s', $description, $m)) {
            $description = trim($m[1]);
        } elseif (preg_match('/(?:^|\n\n)((?:Selling|Up for|Available|For sale|I am|I\'m)[^\n].*)$/s', $description, $m)) {
            $description = trim($m[1]);
        }
    }

    /* Remove placeholders */
    $description = preg_replace('/\[Seller:[^\]]*\]/i', '', $description);
    $description = preg_replace('/\[(?:please|add|confirm|insert)[^\]]*\]/i', '', $description);

    /* Remove markdown */
    $description = preg_replace('/\*\*(.+?)\*\*/s', '$1', $description);
    $description = preg_replace('/^#{1,6}\s*/m', '', $description);
    $description = str_replace('`', '', $description);

    /* Drop meta lines */
    $lines = explode("\n", $description);
    $clean = [];
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '') { $clean[] = ''; continue; }

        if (preg_match('/^(we need|we must|we can|the seller|the user|the instruction|the task|let me|i need|i will|so we|first[,:]|okay[,.]|alright[,.]|task:|prompt:|requirements:|format:|output only|here is|here\'s|note:|answer:)/i', $t)) {
            continue;
        }
        if (preg_match('/^\d+\.\s/', $t) && preg_match('/write|use|include|never|end with|banned/i', $t)) {
            continue;
        }
        $clean[] = $line;
    }
    $description = implode("\n", $clean);

    /* Collapse blank lines */
    $description = preg_replace("/\n{3,}/", "\n\n", $description);
    $description = preg_replace('/[ \t]+$/m', '', $description);
    $description = trim($description);
}

/* ---------- Fallback if empty ---------- */
if ($description === '') {
    $description = "Selling my {$title}. Serious bidders only.";
}

/* ---------- Cap at 2000 chars ---------- */
if (mb_strlen($description) > 2000) {
    $description = mb_substr($description, 0, 2000);
}

echo json_encode([
    'success'     => true,
    'description' => $description,
    'remaining'   => max(0, $max - $_SESSION[$rate_key]['count']),
    'mode'        => 'ai',
]);