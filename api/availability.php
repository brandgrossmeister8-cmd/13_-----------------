<?php
/**
 * API для проверки доступности дат и времени
 */

require_once __DIR__ . '/helpers.php';

// CORS: при credentials нельзя отдавать '*' — отражаем конкретный Origin.
$__origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($__origin !== '') {
    header('Access-Control-Allow-Origin: ' . $__origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
} else {
    header('Access-Control-Allow-Origin: *');
}
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

// Тип записи: 'diagnostic' (по умолчанию), 'consultation' или 'admin' (полная инфо для админки)
$typeParam = $_GET['type'] ?? 'diagnostic';
if (!in_array($typeParam, ['diagnostic', 'consultation', 'admin'], true)) {
    $typeParam = 'diagnostic';
}

if ($month) {
    // Получить доступность для всего месяца
    handleMonthAvailability($month, $typeParam);
} elseif ($date) {
    // Получить доступность слотов для конкретной даты
    handleDateAvailability($date, $typeParam);
} else {
    sendJsonResponse(['success' => false, 'error' => 'Не указан параметр month или date'], 400);
}

/**
 * Получить доступность для месяца
 * @param string $month Месяц в формате YYYY-MM
 */
function handleMonthAvailability($month, $type = 'diagnostic') {
    // 'admin' видит общую картину как для диагностики (по часам)
    $dayType = ($type === 'consultation') ? 'consultation' : 'diagnostic';
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

        // Проверяем правило открытия месяца (запись на следующий месяц доступна только с 20-го числа)
        $today = new DateTime(date('Y-m-d'), new DateTimeZone('Europe/Moscow'));
        $currentMonth = (int)$today->format('m');
        $currentYear = (int)$today->format('Y');
        $currentDay = (int)$today->format('d');

        // Правило открытия месяца действует только для клиентов (запись на
        // следующий месяц доступна с 20-го). Админка ($type === 'admin') видит
        // будущие месяцы всегда — чтобы можно было планировать расписание заранее.
        if ($type !== 'admin'
            && ($year > $currentYear || ($year === $currentYear && $monthNum > $currentMonth))
            && $currentDay < 20) {
            continue;
        }

        // Проверяем, рабочий ли день
        if (!isWorkingDay($dateStr)) {
            continue;
        }

        $dayStatus = getDayStatus($data, $dateStr, $dayType);
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
function handleDateAvailability($date, $type = 'diagnostic') {
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
            'type' => $type,
            'blocked' => true,
            'reason' => $blockReason,
            'slots' => []
        ]);
    }

    // Собираем полную информацию по каждому часу
    $hours = [];
    foreach (WORKING_HOURS as $hour) {
        $hours[] = getHourInfo($data, $date, $hour);
    }

    $slots = [];

    if ($type === 'consultation') {
        // Консультации: каждый час с разбивкой на 20-минутные интервалы
        foreach ($hours as $info) {
            $slots[] = [
                'time'      => $info['hour'],
                'status'    => $info['status'],      // free | partial | full | blocked | diagnostic_booked
                'available' => $info['consultationAvailable'],
                'subSlots'  => $info['subSlots']
            ];
        }
    } elseif ($type === 'admin') {
        // Админка: полная картина по часу
        foreach ($hours as $info) {
            $slots[] = [
                'time'             => $info['hour'],
                'status'           => $info['status'],
                'blocked'          => $info['blocked'],
                'diagnosticBooked' => $info['diagnosticBooked'],
                'consultations'    => $info['consultations'],
                'count'            => $info['count'],
                'subSlots'         => $info['subSlots']
            ];
        }
    } else {
        // Диагностика: один слот = один час, доступен только если час полностью свободен
        foreach ($hours as $info) {
            $slotInfo = [
                'time'      => $info['hour'],
                'available' => $info['diagnosticAvailable'],
                'status'    => $info['status']
            ];
            if (!$info['diagnosticAvailable']) {
                if ($info['blocked']) {
                    $slotInfo['blocked'] = true;
                    $slotInfo['reason'] = 'Заблокировано';
                } elseif ($info['diagnosticBooked']) {
                    $slotInfo['booked'] = true;
                    $slotInfo['reason'] = 'Занято';
                } else {
                    // Час частично занят консультациями — целиком под диагностику не годится
                    $slotInfo['reason'] = 'Занято консультациями';
                }
            }
            $slots[] = $slotInfo;
        }
    }

    sendJsonResponse([
        'success' => true,
        'date' => $date,
        'type' => $type,
        'blocked' => false,
        'slots' => $slots
    ]);
}
