<?php
/**
 * Обработка отправки формы записи
 */

require_once __DIR__ . '/api/helpers.php';

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['success' => false, 'error' => 'Метод не поддерживается'], 405);
}

$postData = getPostData();

// Валидация входных данных
$name = trim($postData['name'] ?? '');
$phone = trim($postData['phone'] ?? '');
$email = trim($postData['email'] ?? '');
$telegram = trim($postData['telegram'] ?? '');
$vk = trim($postData['vk'] ?? '');
$max = trim($postData['max'] ?? '');
$preferredContactRaw = $postData['preferredContact'] ?? [];
$dateRaw = trim($postData['date'] ?? '');
$time = trim($postData['time'] ?? '');
$type = (($postData['type'] ?? 'diagnostic') === 'consultation') ? 'consultation' : 'diagnostic';
$website = trim($postData['website'] ?? '');
$businessRole = trim($postData['businessRole'] ?? '');
$businessAge = trim($postData['businessAge'] ?? '');
$country = trim($postData['country'] ?? '');
$city = trim($postData['city'] ?? '');
$activity = trim($postData['activity'] ?? '');
$socialLinks = trim($postData['socialLinks'] ?? '');
$competitorLinks = trim($postData['competitorLinks'] ?? '');
$problem = trim($postData['problem'] ?? '');

// UTM-метки (источник перехода) — необязательны, только для аналитики
$allowedUtmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
$utmRaw = $postData['utm'] ?? [];
$utm = [];
if (is_array($utmRaw)) {
    foreach ($allowedUtmKeys as $k) {
        if (!empty($utmRaw[$k]) && is_string($utmRaw[$k])) {
            $val = substr(trim($utmRaw[$k]), 0, 200);
            if ($val !== '') {
                $utm[$k] = $val;
            }
        }
    }
}

// Нормализуем preferredContact в массив допустимых значений
$allowedPreferred = ['telegram', 'email', 'vk', 'max'];
$preferredContact = [];
if (is_array($preferredContactRaw)) {
    foreach ($preferredContactRaw as $v) {
        if (is_string($v) && in_array($v, $allowedPreferred, true) && !in_array($v, $preferredContact, true)) {
            $preferredContact[] = $v;
        }
    }
} elseif (is_string($preferredContactRaw) && in_array($preferredContactRaw, $allowedPreferred, true)) {
    // обратная совместимость со старым форматом (строка)
    $preferredContact = [$preferredContactRaw];
}

$errors = [];

// Проверка имени
if (empty($name)) {
    $errors[] = 'Имя не заполнено';
} elseif (!preg_match('/^[а-яёА-ЯЁa-zA-Z\s\-]+$/u', $name)) {
    $errors[] = 'Имя содержит недопустимые символы';
}

// Проверка телефона (обязательное поле)
if (empty($phone)) {
    $errors[] = 'Телефон не заполнен';
} else {
    $phoneDigits = preg_replace('/\D/', '', $phone);
    if (strlen($phoneDigits) !== 11 || $phoneDigits[0] !== '7') {
        $errors[] = 'Некорректный номер телефона';
    }
}

// Проверка предпочтительного способа связи: обязателен и данные должны быть заполнены
if (empty($preferredContact)) {
    $errors[] = 'Выберите хотя бы один способ связи для ссылки Zoom';
} else {
    $labels = ['telegram' => 'Telegram', 'email' => 'Email', 'vk' => 'ВКонтакте', 'max' => 'MAX'];
    $missing = [];
    $telegramNeedsUsername = false;
    foreach ($preferredContact as $m) {
        if ($m === 'telegram') {
            // Нужен именно аккаунт @username — номер телефона не подходит
            if (empty($telegram)) {
                $missing[] = $labels[$m];
            } elseif (!preg_match('/^@[a-zA-Z][a-zA-Z0-9_]{4,31}$/', $telegram)) {
                $telegramNeedsUsername = true;
            }
        } elseif ($m === 'email') {
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $missing[] = $labels[$m];
            }
        } elseif ($m === 'vk' && empty($vk)) {
            $missing[] = $labels[$m];
        } elseif ($m === 'max') {
            $maxDigits = preg_replace('/\D/', '', $max);
            if (empty($max) || strlen($maxDigits) !== 11 || $maxDigits[0] !== '7') {
                $missing[] = $labels[$m];
            }
        }
    }
    if (!empty($missing)) {
        $errors[] = 'Заполните данные по выбранному виду связи: ' . implode(', ', $missing);
    }
    if ($telegramNeedsUsername) {
        $errors[] = 'Укажите аккаунт Telegram в формате @username, а не номер телефона';
    }
}

// Преобразование даты из DD.MM.YYYY в YYYY-MM-DD
if (empty($dateRaw)) {
    $errors[] = 'Дата не выбрана';
} else {
    // Проверяем формат DD.MM.YYYY
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $dateRaw, $matches)) {
        $day = $matches[1];
        $month = $matches[2];
        $year = $matches[3];
        $date = "$year-$month-$day";

        if (!validateDate($date)) {
            $errors[] = 'Некорректная дата';
        }
    } else {
        $errors[] = 'Неверный формат даты';
    }
}

// Проверка времени (с учётом типа записи)
if (empty($time)) {
    $errors[] = 'Время не выбрано';
} elseif (!validateTimeForType($time, $type)) {
    $errors[] = 'Некорректное время';
}

// Ссылка на сайт — только для консультации (в диагностике сайт указывают вместе с соцсетями)
if ($type === 'consultation') {
    if (empty($website)) {
        $errors[] = 'Не указана ссылка на сайт';
    } elseif (!preg_match('~^(https?://)?([^\s/?#.]+\.)+[^\s/?#.\d]{2,}(/\S*)?$~u', $website)) {
        $errors[] = 'Некорректная ссылка на сайт';
    }
}

// Основная роль в бизнесе — обязательна для обоих типов записи
// Запись возможна только для собственника бизнеса
$allowedBusinessRoles = ['owner', 'employee'];
if (empty($businessRole)) {
    $errors[] = 'Не указана роль в бизнесе';
} elseif (!in_array($businessRole, $allowedBusinessRoles, true)) {
    $errors[] = 'Некорректная роль в бизнесе';
} elseif ($businessRole !== 'owner') {
    $errors[] = 'Встреча проводится только с собственником бизнеса';
}

// Возраст бизнеса — обязателен для обоих типов записи
$allowedBusinessAges = ['less_1', '1_3', '3_5', '5_10', 'more_10'];
if (empty($businessAge)) {
    $errors[] = 'Не указано, сколько лет бизнесу';
} elseif (!in_array($businessAge, $allowedBusinessAges, true)) {
    $errors[] = 'Некорректное значение срока работы бизнеса';
}

// Страна и город — обязательны для обоих типов записи
if (empty($country)) {
    $errors[] = 'Не указана страна';
}
if (empty($city)) {
    $errors[] = 'Не указан город';
}

// Описание бизнеса нужно для обоих типов записи
if (empty($activity)) {
    $errors[] = 'Не описано, какой у вас бизнес';
}

// Ссылки на соцсети и конкурентов нужны только для диагностики
if ($type !== 'consultation') {
    // Проверка ссылок на соцсети
    if (empty($socialLinks)) {
        $errors[] = 'Ссылки на соцсети не заполнены';
    }

    // Проверка ссылок на конкурентов
    if (empty($competitorLinks)) {
        $errors[] = 'Ссылки на конкурентов не заполнены';
    }
}

// Проверка описания проблемы (нужна для обоих типов)
if (empty($problem)) {
    $errors[] = 'Проблема не описана';
}

// Если есть ошибки валидации, возвращаем их
if (!empty($errors)) {
    sendJsonResponse([
        'success' => false,
        'error' => implode('; ', $errors),
        'errors' => $errors
    ], 400);
}

// Проверяем, что дата не в прошлом
if (strtotime($date) < strtotime(date('Y-m-d'))) {
    sendJsonResponse([
        'success' => false,
        'error' => 'Нельзя записаться на прошедшую дату'
    ], 400);
}

// Проверяем правило открытия месяца (запись на следующий месяц доступна только с 20-го числа)
$today = new DateTime(date('Y-m-d'), new DateTimeZone('Europe/Moscow'));
$bookingDate = new DateTime($date, new DateTimeZone('Europe/Moscow'));
$bookingMonth = (int)$bookingDate->format('m');
$bookingYear = (int)$bookingDate->format('Y');
$currentMonth = (int)$today->format('m');
$currentYear = (int)$today->format('Y');
$currentDay = (int)$today->format('d');

if (($bookingYear > $currentYear || ($bookingYear === $currentYear && $bookingMonth > $currentMonth)) && $currentDay < 20) {
    sendJsonResponse([
        'success' => false,
        'error' => 'Запись на следующий месяц открывается только с 20-го числа текущего месяца'
    ], 400);
}

// Проверяем, что день рабочий
if (!isWorkingDay($date)) {
    sendJsonResponse([
        'success' => false,
        'error' => 'Выбранный день не является рабочим'
    ], 400);
}

// Проверяем доступность слота
$allData = readJsonData(DATA_FILE);

if (!isSlotAvailableForType($allData, $date, $time, $type)) {
    sendJsonResponse([
        'success' => false,
        'error' => 'Выбранное время уже занято или заблокировано. Пожалуйста, выберите другое время.'
    ], 400);
}

// Создаем запись (telegram_sent=false по умолчанию — контролёр потом досылает)
$newBookingId = null;
$success = atomicJsonUpdate(DATA_FILE, function($data) use ($name, $phone, $email, $telegram, $vk, $max, $preferredContact, $date, $time, $type, $website, $businessRole, $businessAge, $country, $city, $activity, $socialLinks, $competitorLinks, $problem, $utm, &$newBookingId) {
    // Повторная проверка доступности под блокировкой файла — защита от гонки
    if (!isSlotAvailableForType($data, $date, $time, $type)) {
        return $data; // не добавляем; $newBookingId останется null
    }

    $id = generateId($data['bookings']);
    $newBookingId = $id;

    $booking = [
        'id' => $id,
        'date' => $date,
        'time' => $time,
        'type' => $type,
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
        'telegram' => $telegram,
        'vk' => $vk,
        'max' => $max,
        'preferredContact' => $preferredContact,
        'website' => $website,
        'businessRole' => $businessRole,
        'businessAge' => $businessAge,
        'country' => $country,
        'city' => $city,
        'activity' => $activity,
        'socialLinks' => $socialLinks,
        'competitorLinks' => $competitorLinks,
        'problem' => $problem,
        'utm' => $utm,
        'created_at' => date('c'),
        'status' => 'confirmed',
        'telegram_sent' => false,
        'telegram_attempts' => 0,
        'telegram_last_error' => null,
        'telegram_last_attempt' => null
    ];

    $data['bookings'][] = $booking;

    return $data;
});

if (!$success) {
    sendJsonResponse([
        'success' => false,
        'error' => 'Ошибка при сохранении записи. Попробуйте еще раз.'
    ], 500);
}

// Запись не добавлена из-за гонки (слот успели занять между проверкой и блокировкой)
if ($newBookingId === null) {
    sendJsonResponse([
        'success' => false,
        'error' => 'Выбранное время только что заняли. Пожалуйста, выберите другое время.'
    ], 409);
}

// Сразу отвечаем клиенту — НЕ ЗАСТАВЛЯЕМ ЕГО ЖДАТЬ сетевых попыток ТГ
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Connection: close');
echo json_encode([
    'success' => true,
    'message' => 'Ваша заявка успешно отправлена! Мы свяжемся с вами в ближайшее время.'
], JSON_UNESCAPED_UNICODE);

// Закрываем соединение с пользователем (PHP-FPM) и продолжаем работу в фоне
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// Снимаем ограничение времени — фоновая отправка может занять до пары минут
@set_time_limit(0);
@ignore_user_abort(true);

// Только теперь пробуем доставить уведомление в Telegram (3 попытки внутри + другие pending)
try {
    retryPendingTelegramNotifications();
} catch (Throwable $e) {
    @error_log('[send.php] retryPending failed: ' . $e->getMessage());
}
