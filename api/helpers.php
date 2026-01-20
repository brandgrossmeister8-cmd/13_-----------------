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
 * @return bool
 */
function sendTelegramNotification($message) {
    if (empty(TELEGRAM_BOT_TOKEN) || empty(TELEGRAM_CHAT_ID)) {
        return false;
    }

    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";

    $data = [
        'chat_id' => TELEGRAM_CHAT_ID,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($data),
            'timeout' => 10
        ]
    ];

    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);

    return $result !== false;
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
