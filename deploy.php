<?php
/**
 * Скрипт автоматического деплоя через GitHub Webhook
 *
 * Этот скрипт принимает webhook от GitHub и выполняет git pull
 * для обновления файлов на сервере.
 */

// Секретный ключ для проверки запроса от GitHub
// ВАЖНО: Замените на свой секретный ключ!
$secret = 'YOUR_SECRET_KEY_HERE';

// Путь к директории сайта на сервере Beget
// Обычно это: /home/u/username/site.ru/public_html
$repoPath = __DIR__;

// Лог файл
$logFile = __DIR__ . '/deploy.log';

// Функция логирования
function writeLog($message, $logFile) {
    $date = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$date] $message\n", FILE_APPEND | LOCK_EX);
}

// Проверяем метод запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}

// Получаем данные
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

// Проверяем подпись (если секрет настроен)
if ($secret !== 'YOUR_SECRET_KEY_HERE') {
    $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);
    if (!hash_equals($expectedSignature, $signature)) {
        writeLog('ERROR: Invalid signature', $logFile);
        http_response_code(403);
        die('Invalid signature');
    }
}

// Декодируем JSON
$data = json_decode($payload, true);

// Проверяем, что это push в main ветку
$ref = $data['ref'] ?? '';
if ($ref !== 'refs/heads/main') {
    writeLog("Skipped: push to $ref (not main)", $logFile);
    echo "Skipped: not main branch";
    exit;
}

writeLog('Starting deployment...', $logFile);

// Выполняем git pull
$output = [];
$returnCode = 0;

// Сбрасываем локальные изменения и получаем обновления
exec("cd $repoPath && git fetch origin 2>&1", $output, $returnCode);
exec("cd $repoPath && git reset --hard origin/main 2>&1", $output, $returnCode);

if ($returnCode === 0) {
    writeLog('SUCCESS: Deployment completed', $logFile);
    writeLog('Output: ' . implode("\n", $output), $logFile);
    echo "Deployment successful!";
} else {
    writeLog('ERROR: Deployment failed', $logFile);
    writeLog('Output: ' . implode("\n", $output), $logFile);
    http_response_code(500);
    echo "Deployment failed";
}
