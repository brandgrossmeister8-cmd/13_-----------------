<?php
/**
 * API для управления бронированиями
 * Требует авторизации администратора
 */

require_once __DIR__ . '/helpers.php';

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// GET запросы не требуют авторизации (для получения списка)
// Все остальные - требуют
if ($method !== 'GET' || $action) {
    requireAuth();
}

switch ($method) {
    case 'GET':
        if ($action === '') {
            handleGetBookings();
        } else {
            sendJsonResponse(['success' => false, 'error' => 'Неизвестное действие'], 400);
        }
        break;

    case 'POST':
        if ($action === 'block_slot') {
            handleBlockSlot();
        } elseif ($action === 'block_date') {
            handleBlockDate();
        } elseif ($action === 'unblock_slot') {
            handleUnblockSlot();
        } elseif ($action === 'unblock_date') {
            handleUnblockDate();
        } else {
            sendJsonResponse(['success' => false, 'error' => 'Неизвестное действие'], 400);
        }
        break;

    case 'DELETE':
        handleDeleteBooking();
        break;

    default:
        sendJsonResponse(['success' => false, 'error' => 'Метод не поддерживается'], 405);
}

/**
 * Получить все бронирования
 */
function handleGetBookings() {
    $data = readJsonData(DATA_FILE);

    // Сортируем по дате и времени
    usort($data['bookings'], function($a, $b) {
        $dateCompare = strcmp($a['date'], $b['date']);
        if ($dateCompare !== 0) {
            return $dateCompare;
        }
        return strcmp($a['time'], $b['time']);
    });

    sendJsonResponse([
        'success' => true,
        'bookings' => $data['bookings'],
        'blocked_dates' => $data['blocked_dates'],
        'blocked_slots' => $data['blocked_slots']
    ]);
}

/**
 * Удалить бронирование
 */
function handleDeleteBooking() {
    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        sendJsonResponse(['success' => false, 'error' => 'Не указан ID'], 400);
    }

    $success = atomicJsonUpdate(DATA_FILE, function($data) use ($id) {
        $found = false;
        $data['bookings'] = array_filter($data['bookings'], function($booking) use ($id, &$found) {
            if ($booking['id'] == $id) {
                $found = true;
                return false;
            }
            return true;
        });

        // Переиндексируем массив
        $data['bookings'] = array_values($data['bookings']);

        if (!$found) {
            throw new Exception('Запись не найдена');
        }

        return $data;
    });

    if ($success) {
        sendJsonResponse([
            'success' => true,
            'message' => 'Запись удалена'
        ]);
    } else {
        sendJsonResponse([
            'success' => false,
            'error' => 'Ошибка при удалении'
        ], 500);
    }
}

/**
 * Заблокировать слот времени
 */
function handleBlockSlot() {
    $postData = getPostData();

    $date = $postData['date'] ?? '';
    $time = $postData['time'] ?? '';
    $reason = $postData['reason'] ?? 'Заблокировано администратором';

    if (!validateDate($date)) {
        sendJsonResponse(['success' => false, 'error' => 'Неверный формат даты'], 400);
    }

    if (!validateTime($time)) {
        sendJsonResponse(['success' => false, 'error' => 'Неверное время'], 400);
    }

    $success = atomicJsonUpdate(DATA_FILE, function($data) use ($date, $time, $reason) {
        // Проверяем, не заблокирован ли уже
        foreach ($data['blocked_slots'] as $blocked) {
            if ($blocked['date'] === $date && $blocked['time'] === $time) {
                throw new Exception('Слот уже заблокирован');
            }
        }

        $data['blocked_slots'][] = [
            'date' => $date,
            'time' => $time,
            'reason' => $reason,
            'created_at' => date('c')
        ];

        return $data;
    });

    if ($success) {
        sendJsonResponse([
            'success' => true,
            'message' => 'Слот заблокирован'
        ]);
    } else {
        sendJsonResponse([
            'success' => false,
            'error' => 'Ошибка при блокировке'
        ], 500);
    }
}

/**
 * Разблокировать слот времени
 */
function handleUnblockSlot() {
    $postData = getPostData();

    $date = $postData['date'] ?? '';
    $time = $postData['time'] ?? '';

    if (!validateDate($date)) {
        sendJsonResponse(['success' => false, 'error' => 'Неверный формат даты'], 400);
    }

    if (!validateTime($time)) {
        sendJsonResponse(['success' => false, 'error' => 'Неверное время'], 400);
    }

    $success = atomicJsonUpdate(DATA_FILE, function($data) use ($date, $time) {
        $found = false;
        $data['blocked_slots'] = array_filter($data['blocked_slots'], function($blocked) use ($date, $time, &$found) {
            if ($blocked['date'] === $date && $blocked['time'] === $time) {
                $found = true;
                return false;
            }
            return true;
        });

        $data['blocked_slots'] = array_values($data['blocked_slots']);

        if (!$found) {
            throw new Exception('Блокировка не найдена');
        }

        return $data;
    });

    if ($success) {
        sendJsonResponse([
            'success' => true,
            'message' => 'Слот разблокирован'
        ]);
    } else {
        sendJsonResponse([
            'success' => false,
            'error' => 'Ошибка при разблокировке'
        ], 500);
    }
}

/**
 * Заблокировать весь день
 */
function handleBlockDate() {
    $postData = getPostData();

    $date = $postData['date'] ?? '';
    $reason = $postData['reason'] ?? 'Заблокировано администратором';

    if (!validateDate($date)) {
        sendJsonResponse(['success' => false, 'error' => 'Неверный формат даты'], 400);
    }

    $success = atomicJsonUpdate(DATA_FILE, function($data) use ($date, $reason) {
        // Проверяем, не заблокирован ли уже
        foreach ($data['blocked_dates'] as $blocked) {
            if ($blocked['date'] === $date && $blocked['all_day']) {
                throw new Exception('День уже заблокирован');
            }
        }

        $data['blocked_dates'][] = [
            'date' => $date,
            'reason' => $reason,
            'all_day' => true,
            'created_at' => date('c')
        ];

        return $data;
    });

    if ($success) {
        sendJsonResponse([
            'success' => true,
            'message' => 'День заблокирован'
        ]);
    } else {
        sendJsonResponse([
            'success' => false,
            'error' => 'Ошибка при блокировке'
        ], 500);
    }
}

/**
 * Разблокировать весь день
 */
function handleUnblockDate() {
    $postData = getPostData();

    $date = $postData['date'] ?? '';

    if (!validateDate($date)) {
        sendJsonResponse(['success' => false, 'error' => 'Неверный формат даты'], 400);
    }

    $success = atomicJsonUpdate(DATA_FILE, function($data) use ($date) {
        $found = false;
        $data['blocked_dates'] = array_filter($data['blocked_dates'], function($blocked) use ($date, &$found) {
            if ($blocked['date'] === $date && $blocked['all_day']) {
                $found = true;
                return false;
            }
            return true;
        });

        $data['blocked_dates'] = array_values($data['blocked_dates']);

        if (!$found) {
            throw new Exception('Блокировка дня не найдена');
        }

        return $data;
    });

    if ($success) {
        sendJsonResponse([
            'success' => true,
            'message' => 'День разблокирован'
        ]);
    } else {
        sendJsonResponse([
            'success' => false,
            'error' => 'Ошибка при разблокировке'
        ], 500);
    }
}
