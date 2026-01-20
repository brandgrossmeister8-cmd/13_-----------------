<?php
/**
 * API для проверки доступности дат и времени
 */

require_once __DIR__ . '/helpers.php';

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse(['success' => false, 'error' => 'Метод не поддерживается'], 405);
}

$month = $_GET['month'] ?? null;
$date = $_GET['date'] ?? null;

if ($month) {
    // Получить доступность для всего месяца
    handleMonthAvailability($month);
} elseif ($date) {
    // Получить доступность слотов для конкретной даты
    handleDateAvailability($date);
} else {
    sendJsonResponse(['success' => false, 'error' => 'Не указан параметр month или date'], 400);
}

/**
 * Получить доступность для месяца
 * @param string $month Месяц в формате YYYY-MM
 */
function handleMonthAvailability($month) {
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        sendJsonResponse(['success' => false, 'error' => 'Неверный формат месяца. Используйте YYYY-MM'], 400);
    }

    $data = readJsonData(DATA_FILE);

    list($year, $monthNum) = explode('-', $month);
    $year = (int)$year;
    $monthNum = (int)$monthNum;

    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);

    $dates = [];

    for ($day = 1; $day <= $daysInMonth; $day++) {
        $dateStr = sprintf('%04d-%02d-%02d', $year, $monthNum, $day);

        // Проверяем, что дата не в прошлом
        if (strtotime($dateStr) < strtotime(date('Y-m-d'))) {
            continue;
        }

        // Проверяем, рабочий ли день
        if (!isWorkingDay($dateStr)) {
            continue;
        }

        $dayStatus = getDayStatus($data, $dateStr);
        $dates[$dateStr] = $dayStatus;
    }

    sendJsonResponse([
        'success' => true,
        'dates' => $dates
    ]);
}

/**
 * Получить доступность слотов для конкретной даты
 * @param string $date Дата в формате YYYY-MM-DD
 */
function handleDateAvailability($date) {
    if (!validateDate($date)) {
        sendJsonResponse(['success' => false, 'error' => 'Неверный формат даты. Используйте YYYY-MM-DD'], 400);
    }

    $data = readJsonData(DATA_FILE);

    // Проверяем блокировку всего дня
    $dayBlocked = false;
    $blockReason = null;
    foreach ($data['blocked_dates'] as $blocked) {
        if ($blocked['date'] === $date && $blocked['all_day']) {
            $dayBlocked = true;
            $blockReason = $blocked['reason'] ?? null;
            break;
        }
    }

    if ($dayBlocked) {
        sendJsonResponse([
            'success' => true,
            'date' => $date,
            'blocked' => true,
            'reason' => $blockReason,
            'slots' => []
        ]);
    }

    // Получаем информацию о каждом слоте
    $slots = [];
    foreach (WORKING_HOURS as $time) {
        $available = isSlotAvailable($data, $date, $time);

        $slotInfo = [
            'time' => $time,
            'available' => $available
        ];

        // Если слот недоступен, пытаемся найти причину
        if (!$available) {
            // Проверяем блокировку слота
            foreach ($data['blocked_slots'] as $blocked) {
                if ($blocked['date'] === $date && $blocked['time'] === $time) {
                    $slotInfo['reason'] = $blocked['reason'] ?? 'Заблокировано';
                    $slotInfo['blocked'] = true;
                    break;
                }
            }

            // Проверяем бронирование
            if (!isset($slotInfo['blocked'])) {
                foreach ($data['bookings'] as $booking) {
                    if ($booking['date'] === $date && $booking['time'] === $time) {
                        $slotInfo['reason'] = 'Занято';
                        $slotInfo['booked'] = true;
                        break;
                    }
                }
            }
        }

        $slots[] = $slotInfo;
    }

    sendJsonResponse([
        'success' => true,
        'date' => $date,
        'blocked' => false,
        'slots' => $slots
    ]);
}
