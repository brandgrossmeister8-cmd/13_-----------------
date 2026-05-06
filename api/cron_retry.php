<?php
/**
 * Cron-эндпоинт: пытается доотправить все Telegram-уведомления, у которых
 * telegram_sent === false. Запускается раз в 5 минут — рано или поздно
 * связь с api.telegram.org «откроется» на минуту, и сообщения уйдут.
 *
 * Запуск только из CLI (через cron) — не доступен по HTTP.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/helpers.php';

// Параметры свободнее, чем при онлайн-вызовах:
// до 20 заявок за раз, до 100 попыток на запись, кулдаун 60 сек.
$stats = retryPendingTelegramNotifications(20, 100, 60);

echo '[' . date('c') . '] retry stats: ' . json_encode($stats, JSON_UNESCAPED_UNICODE) . "\n";
