<?php
/**
 * Вспомогательные функции для API
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Проверка авторизации администратора
 * @throws Exception если не авторизован
 */
function requireAuth() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['admin_authenticated']) || $_SESSION['admin_authenticated'] !== true) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }
}

/**
 * Атомарная запись в JSON файл с блокировкой
 * @param string $file Путь к файлу
 * @param callable $callback Функция для модификации данных
 * @return bool Успешность операции
 */
function atomicJsonUpdate($file, $callback) {
    $fp = fopen($file, 'c+');
    if (!$fp) {
        return false;
    }

    // Получаем эксклюзивную блокировку
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }

    // Читаем содержимое
    $content = '';
    while (!feof($fp)) {
        $content .= fread($fp, 8192);
    }

    $data = json_decode($content ?: '{"bookings":[],"blocked_dates":[],"blocked_slots":[]}', true);

    if ($data === null) {
        $data = [
            'bookings' => [],
            'blocked_dates' => [],
            'blocked_slots' => []
        ];
    }

    // Применяем модификацию
    $data = $callback($data);

    // Записываем обратно
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // Снимаем блокировку и закрываем файл
    flock($fp, LOCK_UN);
    fclose($fp);

    return true;
}

/**
 * Чтение данных из JSON файла
 * @param string $file Путь к файлу
 * @return array Данные
 */
function readJsonData($file) {
    if (!file_exists($file)) {
        return [
            'bookings' => [],
            'blocked_dates' => [],
            'blocked_slots' => []
        ];
    }

    $content = file_get_contents($file);
    $data = json_decode($content, true);

    if ($data === null) {
        return [
            'bookings' => [],
            'blocked_dates' => [],
            'blocked_slots' => []
        ];
    }

    return $data;
}

/**
 * Валидация даты в формате YYYY-MM-DD
 * @param string $date Дата
 * @return bool
 */
function validateDate($date) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $parts = explode('-', $date);
    return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
}

/**
 * Валидация времени
 * @param string $time Время в формате HH:MM
 * @return bool
 */
function validateTime($time) {
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        return false;
    }

    return in_array($time, WORKING_HOURS);
}

/**
 * Проверка, является ли дата рабочим днем
 * @param string $date Дата в формате YYYY-MM-DD
 * @return bool
 */
function isWorkingDay($date) {
    $timestamp = strtotime($date);
    $dayOfWeek = (int)date('N', $timestamp); // 1 = понедельник, 7 = воскресенье

    return in_array($dayOfWeek, WORKING_DAYS);
}

/**
 * Генерация ID для новой записи
 * @param array $data Массив с данными
 * @return int
 */
function generateId($data) {
    if (empty($data)) {
        return 1;
    }

    $maxId = 0;
    foreach ($data as $item) {
        if (isset($item['id']) && $item['id'] > $maxId) {
            $maxId = $item['id'];
        }
    }

    return $maxId + 1;
}

/**
 * Проверка доступности слота
 * @param array $allData Все данные из JSON
 * @param string $date Дата
 * @param string $time Время
 * @return bool
 */
function isSlotAvailable($allData, $date, $time) {
    // Проверяем блокировку всего дня
    foreach ($allData['blocked_dates'] as $blocked) {
        if ($blocked['date'] === $date && $blocked['all_day']) {
            return false;
        }
    }

    // Проверяем блокировку конкретного слота
    foreach ($allData['blocked_slots'] as $blocked) {
        if ($blocked['date'] === $date && $blocked['time'] === $time) {
            return false;
        }
    }

    // Проверяем существующие бронирования
    foreach ($allData['bookings'] as $booking) {
        if ($booking['date'] === $date && $booking['time'] === $time) {
            return false;
        }
    }

    return true;
}

/**
 * Получение статуса дня (free/partial/full/blocked)
 * @param array $allData Все данные
 * @param string $date Дата
 * @return array ['status' => string, 'available_slots' => array, 'total_slots' => int]
 */
function getDayStatus($allData, $date) {
    // Проверяем полную блокировку дня
    foreach ($allData['blocked_dates'] as $blocked) {
        if ($blocked['date'] === $date && $blocked['all_day']) {
            return [
                'status' => 'blocked',
                'available_slots' => [],
                'total_slots' => count(WORKING_HOURS),
                'reason' => $blocked['reason'] ?? null
            ];
        }
    }

    $availableSlots = [];
    $totalSlots = count(WORKING_HOURS);

    foreach (WORKING_HOURS as $time) {
        if (isSlotAvailable($allData, $date, $time)) {
            $availableSlots[] = $time;
        }
    }

    $availableCount = count($availableSlots);

    if ($availableCount === 0) {
        $status = 'full';
    } elseif ($availableCount === $totalSlots) {
        $status = 'free';
    } else {
        $status = 'partial';
    }

    return [
        'status' => $status,
        'available_slots' => $availableSlots,
        'total_slots' => $totalSlots
    ];
}

/**
 * Отправка уведомления в Telegram
 * @param string $message Сообщение
 * @return array ['ok' => bool, 'http_code' => int, 'response' => string, 'error' => string]
 */
function sendTelegramNotification($message) {
    if (empty(TELEGRAM_BOT_TOKEN) || empty(TELEGRAM_CHAT_ID)) {
        $res = ['ok' => false, 'http_code' => 0, 'response' => '', 'error' => 'TELEGRAM_BOT_TOKEN или TELEGRAM_CHAT_ID не заданы'];
        logTelegramResult($res, $message);
        return $res;
    }

    if (!function_exists('curl_init')) {
        $res = ['ok' => false, 'http_code' => 0, 'response' => '', 'error' => 'curl extension не установлен на сервере'];
        logTelegramResult($res, $message);
        return $res;
    }

    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";

    $data = [
        'chat_id' => TELEGRAM_CHAT_ID,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $ok = ($response !== false && $httpCode === 200);
    $errorMsg = '';
    if (!$ok) {
        if ($curlError) {
            $errorMsg = 'cURL: ' . $curlError;
        } elseif ($httpCode !== 200) {
            $errorMsg = 'HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 500);
        } else {
            $errorMsg = 'unknown';
        }
    }

    $result = [
        'ok' => $ok,
        'http_code' => $httpCode,
        'response' => is_string($response) ? $response : '',
        'error' => $errorMsg
    ];

    if (!$ok) {
        logTelegramResult($result, $message);
    }

    return $result;
}

/**
 * Собрать сообщение для Telegram по записи (используется и при первой отправке, и при ретраях)
 * @param array $booking
 * @param bool $isRetry Признак повторной отправки
 * @return string
 */
function buildTelegramMessageForBooking($booking, $isRetry = false) {
    $title = $isRetry ? "🔁 <b>Доставка после сбоя — запись на диагностику</b>" : "🆕 <b>Новая запись на диагностику</b>";
    $msg  = $title . "\n\n";
    $msg .= "📅 <b>Дата:</b> " . date('d.m.Y', strtotime($booking['date'])) . "\n";
    $msg .= "🕐 <b>Время:</b> " . htmlspecialchars($booking['time']) . "\n\n";
    $msg .= "👤 <b>Имя:</b> " . htmlspecialchars($booking['name']) . "\n";
    $msg .= "💬 <b>Telegram:</b> " . htmlspecialchars($booking['telegram'] ?? '—') . "\n";
    if (!empty($booking['phone'])) {
        $msg .= "📱 <b>Телефон:</b> " . htmlspecialchars($booking['phone']) . "\n";
    }
    if (!empty($booking['email'])) {
        $msg .= "📧 <b>Email:</b> " . htmlspecialchars($booking['email']) . "\n";
    }
    if (!empty($booking['socialLinks'])) {
        $msg .= "\n🔗 <b>Ссылки на соцсети/сайт:</b>\n" . htmlspecialchars($booking['socialLinks']) . "\n";
    }
    if (!empty($booking['competitorLinks'])) {
        $msg .= "\n🎯 <b>Ссылки на конкурентов:</b>\n" . htmlspecialchars($booking['competitorLinks']) . "\n";
    }
    if (!empty($booking['problem'])) {
        $msg .= "\n📝 <b>Проблема:</b>\n" . htmlspecialchars($booking['problem']) . "\n";
    }
    $created = !empty($booking['created_at']) ? date('d.m.Y H:i', strtotime($booking['created_at'])) : '—';
    $msg .= "\n⏰ <b>Создано:</b> $created";
    if ($isRetry && !empty($booking['telegram_attempts'])) {
        $msg .= "\n🔢 <b>Попытка:</b> " . ((int)$booking['telegram_attempts'] + 1);
    }
    return $msg;
}

/**
 * Контролёр: находит все заявки с telegram_sent=false и пытается переотправить.
 * Вызывается при подаче новой заявки и при загрузке админки.
 *
 * @param int $maxPerRun Максимум заявок за один вызов (защита от долгих циклов)
 * @param int $maxAttempts Максимум попыток на одну заявку
 * @param int $cooldownSeconds Не повторять чаще чем раз в N секунд для одной заявки
 * @return array ['attempted' => int, 'sent' => int, 'failed' => int]
 */
function retryPendingTelegramNotifications($maxPerRun = 5, $maxAttempts = 10, $cooldownSeconds = 30) {
    $stats = ['attempted' => 0, 'sent' => 0, 'failed' => 0];

    // Шаг 1: читаем без блокировки и отбираем кандидатов (старые записи без поля
    // не трогаем — для них есть ручная кнопка «Отправить в ТГ»)
    $data = readJsonData(DATA_FILE);
    $now = time();
    $candidates = [];
    foreach ($data['bookings'] as $b) {
        if (!array_key_exists('telegram_sent', $b)) continue;
        if ($b['telegram_sent'] === true) continue;

        $attempts = (int)($b['telegram_attempts'] ?? 0);
        if ($attempts >= $maxAttempts) continue;

        $lastAttempt = !empty($b['telegram_last_attempt']) ? strtotime($b['telegram_last_attempt']) : 0;
        if ($lastAttempt && ($now - $lastAttempt) < $cooldownSeconds) continue;

        $candidates[] = $b;
        if (count($candidates) >= $maxPerRun) break;
    }

    // Шаг 2: для каждой попытки делаем сетевой вызов БЕЗ блокировки JSON,
    // потом точечно обновляем только нужную запись (короткая блокировка)
    foreach ($candidates as $booking) {
        $stats['attempted']++;
        $attempts = (int)($booking['telegram_attempts'] ?? 0);
        $message = buildTelegramMessageForBooking($booking, $attempts > 0);
        $result = sendTelegramNotification($message);

        $bookingId = (int)($booking['id'] ?? 0);
        $ok = !empty($result['ok']);
        $errMsg = $ok ? null : ($result['error'] ?? 'unknown');

        atomicJsonUpdate(DATA_FILE, function($d) use ($bookingId, $ok, $errMsg) {
            foreach ($d['bookings'] as $i => $b) {
                if ((int)($b['id'] ?? 0) !== $bookingId) continue;
                $d['bookings'][$i]['telegram_attempts'] = (int)($b['telegram_attempts'] ?? 0) + 1;
                $d['bookings'][$i]['telegram_last_attempt'] = date('c');
                if ($ok) {
                    $d['bookings'][$i]['telegram_sent'] = true;
                    $d['bookings'][$i]['telegram_last_error'] = null;
                } else {
                    $d['bookings'][$i]['telegram_last_error'] = $errMsg;
                }
                break;
            }
            return $d;
        });

        if ($ok) {
            $stats['sent']++;
        } else {
            $stats['failed']++;
        }
    }

    return $stats;
}

/**
 * Запись результата отправки в лог-файл (только при ошибке)
 */
function logTelegramResult($result, $message) {
    $logFile = __DIR__ . '/../data/telegram.log';
    $line = '[' . date('c') . '] '
        . 'http=' . ($result['http_code'] ?? 0) . ' '
        . 'error=' . ($result['error'] ?? '') . ' '
        . 'response=' . substr((string)($result['response'] ?? ''), 0, 300) . ' '
        . 'msg_preview=' . substr(preg_replace('/\s+/', ' ', $message), 0, 100)
        . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Отправка JSON ответа
 * @param array $data Данные для отправки
 * @param int $code HTTP код ответа
 */
function sendJsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Получение данных из POST запроса
 * @return array|null
 */
function getPostData() {
    $input = file_get_contents('php://input');
    return json_decode($input, true);
}
