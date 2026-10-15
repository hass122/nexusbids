<?php
/**
 * NexusBids AI Chatbot — AJAX Endpoint
 * - Always returns JSON
 * - Soft CSRF check (non-blocking)
 * - Handles all errors gracefully
 */

error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set("Asia/Karachi");

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* ---------- JSON output helper ---------- */
function nb_chat_json_out(bool $ok, array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(
        array_merge(['success' => $ok], $data),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

try {

    /* ---- Load dependencies ---- */
    $db_file = __DIR__ . '/config/database.php';
    if (!file_exists($db_file)) {
        nb_chat_json_out(false, ['error' => 'Database config not found.'], 500);
    }
    require_once $db_file;

    if (file_exists(__DIR__ . '/includes/csrf.php')) {
        require_once __DIR__ . '/includes/csrf.php';
    }

    if (!file_exists(__DIR__ . '/includes/ai-chatbot.php')) {
        nb_chat_json_out(false, ['error' => 'AI engine file not found.'], 500);
    }
    require_once __DIR__ . '/includes/ai-chatbot.php';

    if (!isset($conn) || !($conn instanceof mysqli)) {
        nb_chat_json_out(false, ['error' => 'Database connection failed.'], 500);
    }

    /* ---- POST only ---- */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        nb_chat_json_out(false, ['error' => 'Invalid request method. Use POST.'], 405);
    }

    /* ---- Session ---- */
    if (empty($_SESSION['ai_chat_session'])) {
        $_SESSION['ai_chat_session'] = bin2hex(random_bytes(16));
    }
    $sid = $_SESSION['ai_chat_session'];

    $action = $_POST['action'] ?? 'send';

    /* ---- CSRF (soft check — never blocks with plain text) ---- */
    $csrf_ok = true;
    $csrf_err = '';
    if ($action !== 'ping' && function_exists('nb_csrf_check')) {
        try {
            ob_start();
            nb_csrf_check();
            $out = ob_get_clean();
            if (!empty($out)) {
                $csrf_ok = false;
                $csrf_err = trim(strip_tags($out));
            }
        } catch (Throwable $e) {
            if (ob_get_level())
                ob_end_clean();
            $csrf_ok = false;
            $csrf_err = $e->getMessage();
        }
    }

    /* ---- Config ---- */
    $cfg = nb_chat_config();
    $user_id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $rateKey = 'chat_' . ($user_id ?: 'ip_' . ($_SERVER['REMOTE_ADDR'] ?? '0'));

    /* ---- Actions ---- */
    if ($action === 'ping') {
        nb_chat_json_out(true, [
            'pong' => true,
            'time' => date('H:i:s'),
            'csrf_ok' => $csrf_ok,
            'provider' => $cfg['provider'],
            'model' => $cfg['gemini_model'],
            'has_key' => !empty($cfg['gemini_key']),
        ]);
    }

    if (!$csrf_ok) {
        nb_chat_json_out(false, [
            'error' => 'Security check failed. Please refresh the page and try again.',
        ], 403);
    }

    if ($action === 'clear') {
        nb_chat_clear($conn, $sid);
        nb_chat_json_out(true, ['message' => 'Chat cleared.']);
    }

    if ($action === 'history') {
        $history = nb_chat_history($conn, $sid, 30);
        nb_chat_json_out(true, ['history' => $history]);
    }

    /* ---- Rate limit ---- */
    if (!nb_chat_rate_ok($conn, $rateKey, $cfg['max_per_5min'], 300)) {
        nb_chat_json_out(false, [
            'error' => 'You are sending messages too quickly. Please wait a minute.'
        ], 429);
    }

    /* ---- Message ---- */
    $message = trim((string) ($_POST['message'] ?? ''));
    if ($message === '') {
        nb_chat_json_out(false, ['error' => 'Please type a message.'], 400);
    }
    if (mb_strlen($message) > $cfg['max_msg_len']) {
        nb_chat_json_out(false, [
            'error' => 'Message is too long (max ' . $cfg['max_msg_len'] . ' chars).'
        ], 400);
    }

    /* ---- Context ---- */
    $ctx = [
        'user_id' => $user_id,
        'name' => $_SESSION['full_name'] ?? 'Guest',
        'role' => $_SESSION['role'] ?? 'visitor',
        'unread' => 0,
    ];

    if ($user_id && file_exists(__DIR__ . '/includes/notifications.php')) {
        require_once __DIR__ . '/includes/notifications.php';
        if (function_exists('nb_unread_count')) {
            try {
                $ctx['unread'] = (int) nb_unread_count($conn, $user_id);
            } catch (Throwable $e) {
            }
        }
    }

    /* ---- Save + history ---- */
    nb_chat_save($conn, $sid, $user_id, 'user', $message);
    $history = nb_chat_history($conn, $sid, 10);

    /* ---- System prompt ---- */
    $system = nb_chat_system_prompt($ctx);

    try {
        $stats_res = $conn->query("
            SELECT
              (SELECT COUNT(*) FROM auctions WHERE auction_status='active' AND end_time > NOW()) AS live_auctions,
              (SELECT COUNT(*) FROM categories WHERE status='active') AS categories
        ");
        if ($stats_res) {
            $s = $stats_res->fetch_assoc();
            if ($s) {
                $system .= "\nLIVE SITE STATS:\n"
                    . "- Live auctions: " . (int) $s['live_auctions'] . "\n"
                    . "- Active categories: " . (int) $s['categories'] . "\n";
            }
        }
    } catch (Throwable $e) {
    }

    /* ---- Call AI ---- */
    if (!function_exists('nb_chat_send')) {
        nb_chat_json_out(false, ['error' => 'AI function not found.'], 500);
    }

    $result = nb_chat_send($history, $system);

    if (empty($result['ok'])) {
        nb_chat_json_out(false, [
            'error' => $result['error'] ?? 'AI is unavailable right now.',
            'code' => $result['code'] ?? 0,
        ], 502);
    }

    $reply = trim((string) ($result['text'] ?? ''));
    if ($reply === '') {
        nb_chat_json_out(false, ['error' => 'AI returned empty response.'], 502);
    }

    /* ---- Save reply + return ---- */
    nb_chat_save($conn, $sid, $user_id, 'assistant', $reply);

    nb_chat_json_out(true, [
        'reply' => $reply,
        'time' => date('h:i A'),
    ]);

} catch (Throwable $e) {
    error_log("ai-chat-api fatal: " . $e->getMessage());
    nb_chat_json_out(false, [
        'error' => 'Server error: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ], 500);
}