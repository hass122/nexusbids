<?php
/*
|--------------------------------------------------------------------------
| Payments bootstrap — loaded at the top of every gateway file
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set("Asia/Karachi");

$root = dirname(__DIR__);

require_once $root . "/config/database.php";
require_once $root . "/includes/csrf.php";
require_once $root . "/includes/notifications.php";
require_once $root . "/includes/payment-helpers.php";

if (isset($conn) && $conn instanceof mysqli) {
    $conn->query("SET time_zone = '+05:00'");
}

/* =========================================================
   AUTO-DETECT THE PROJECT BASE URL
========================================================= */
if (!defined('NB_BASE_URL')) {
    $doc_root = str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $proj_root = str_replace('\\', '/', (string) realpath(dirname(__DIR__)));

    $base = '';
    if ($doc_root !== '' && $proj_root !== '' && strpos($proj_root, $doc_root) === 0) {
        $base = substr($proj_root, strlen($doc_root));
        $base = rtrim($base, '/');
    }
    define('NB_BASE_URL', $base);
}

/**
 * Turn a relative path like "/user/orders.php" into "/Hassan1/user/orders.php"
 * Leaves http(s):// URLs alone.
 */
function nb_url($path)
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return NB_BASE_URL . '/' . ltrim($path, '/');
}

/**
 * Cached gateway config loader (only reads file once per request).
 */
function nb_gateway_configs()
{
    static $configs = null;
    if ($configs === null) {
        $file = dirname(__DIR__) . "/config/payment-gateways.php";
        $configs = is_file($file) ? (require $file) : [];
        if (!is_array($configs)) {
            $configs = [];
        }
    }
    return $configs;
}

/**
 * Return the gateway config array for a given method key.
 * Returns null if the gateway is not enabled.
 */
function nb_gateway_config($method)
{
    $configs = nb_gateway_configs();
    if (!isset($configs[$method]) || empty($configs[$method]['enabled'])) {
        return null;
    }
    return $configs[$method];
}

/**
 * Render a branded error page and exit.
 */
function nb_gateway_die($title, $message, $back_url = '/user/orders.php')
{
    $back = nb_url($back_url);
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    ?><!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="utf-8">
        <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> · NexusBids</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    </head>

    <body class="bg-slate-50 flex items-center justify-center min-h-screen font-sans">
        <div class="max-w-md w-full bg-white rounded-2xl p-8 shadow-lg border border-slate-200 text-center">
            <div
                class="w-16 h-16 mx-auto rounded-full bg-rose-100 text-rose-600 flex items-center justify-center text-2xl mb-4">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <h1 class="text-xl font-black text-slate-900 mb-2"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="text-sm text-slate-500 mb-6"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
            <a href="<?= htmlspecialchars($back, ENT_QUOTES, 'UTF-8') ?>"
                class="inline-block px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-sm font-semibold">
                Back to Orders
            </a>
        </div>
    </body>

    </html>
    <?php
    exit();
}

/**
 * Small helper: safely fetch a scalar from array.
 */
function nb_arr($arr, $key, $default = null)
{
    return (is_array($arr) && array_key_exists($key, $arr) && $arr[$key] !== '') ? $arr[$key] : $default;
}