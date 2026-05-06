<?php
/**
 * Диагностика отправки в Telegram с сервера.
 * Требует авторизации админа (войдите в админку перед открытием).
 *
 * Откройте: https://formareg.brandgrossmeister.ru/api/diag.php
 */

require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');

// Защита: тот же session-based auth, что и в bookings.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['admin_authenticated']) || $_SESSION['admin_authenticated'] !== true) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'Требуется авторизация. Сначала войдите в админ-панель.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$report = [
    'php_version' => PHP_VERSION,
    'curl_loaded' => function_exists('curl_init'),
    'openssl_loaded' => extension_loaded('openssl'),
    'allow_url_fopen' => (bool)ini_get('allow_url_fopen'),
    'telegram_token_configured' => !empty(TELEGRAM_BOT_TOKEN),
    'telegram_chat_id_configured' => !empty(TELEGRAM_CHAT_ID),
    'data_dir_writable' => is_writable(__DIR__ . '/../data'),
    'log_file_path' => __DIR__ . '/../data/telegram.log',
];

if (function_exists('curl_version')) {
    $cv = curl_version();
    $report['curl_version'] = $cv['version'] ?? 'unknown';
    $report['curl_ssl_version'] = $cv['ssl_version'] ?? 'unknown';
}

$apiBase = defined('TELEGRAM_API_BASE') ? rtrim(TELEGRAM_API_BASE, '/') : 'https://api.telegram.org';
$report['telegram_api_base'] = $apiBase;
$report['force_ipv6'] = defined('TELEGRAM_FORCE_IPV6') && TELEGRAM_FORCE_IPV6;

// Тест 1: достучаться до Telegram API через настроенный base
$report['tests'] = [];
if (function_exists('curl_init') && !empty(TELEGRAM_BOT_TOKEN)) {
    $ch = curl_init($apiBase . '/bot' . TELEGRAM_BOT_TOKEN . '/getMe');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    if (defined('TELEGRAM_FORCE_IPV6') && TELEGRAM_FORCE_IPV6) {
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V6);
    }
    $resp = curl_exec($ch);
    $report['tests']['getMe'] = [
        'http_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'curl_error' => curl_error($ch),
        'response_preview' => substr((string)$resp, 0, 400)
    ];
    curl_close($ch);
}

// Тест 2: реальная отправка тестового сообщения через нашу функцию
$testMsg = "🩺 <b>Тест диагностики</b>\nЕсли вы это видите — Telegram-канал доставки работает.\nВремя: " . date('d.m.Y H:i:s');
$sendResult = sendTelegramNotification($testMsg);
$report['tests']['sendMessage'] = $sendResult;

// Тест 3: размер последнего лога ошибок
$logPath = __DIR__ . '/../data/telegram.log';
if (file_exists($logPath)) {
    $report['log_tail'] = array_slice(
        explode("\n", trim(@file_get_contents($logPath))),
        -10
    );
} else {
    $report['log_tail'] = '(лог-файла ещё нет)';
}

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
