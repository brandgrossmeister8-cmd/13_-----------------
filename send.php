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
$dateRaw = trim($postData['date'] ?? '');
$time = trim($postData['time'] ?? '');
$socialLinks = trim($postData['socialLinks'] ?? '');
$competitorLinks = trim($postData['competitorLinks'] ?? '');
$problem = trim($postData['problem'] ?? '');

$errors = [];

// Проверка имени
if (empty($name)) {
    $errors[] = 'Имя не заполнено';
} elseif (!preg_match('/^[а-яёА-ЯЁa-zA-Z\s\-]+$/u', $name)) {
    $errors[] = 'Имя содержит недопустимые символы';
}

// Проверка телефона (необязательное поле, но если заполнено - проверяем формат)
if (!empty($phone)) {
    $phoneDigits = preg_replace('/\D/', '', $phone);
    if (strlen($phoneDigits) !== 11 || $phoneDigits[0] !== '7') {
        $errors[] = 'Некорректный номер телефона';
    }
}

// Проверка email (необязательное поле, но если заполнено - проверяем формат)
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Некорректный email';
}

// Проверка Telegram (обязательное поле)
if (empty($telegram)) {
    $errors[] = 'Telegram не заполнен';
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

// Проверка времени
if (empty($time)) {
    $errors[] = 'Время не выбрано';
} elseif (!validateTime($time)) {
    $errors[] = 'Некорректное время';
}

// Проверка ссылок на соцсети
if (empty($socialLinks)) {
    $errors[] = 'Ссылки на соцсети не заполнены';
}

// Проверка ссылок на конкурентов
if (empty($competitorLinks)) {
    $errors[] = 'Ссылки на конкурентов не заполнены';
}

// Проверка описания проблемы
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

// Проверяем, что день рабочий
if (!isWorkingDay($date)) {
    sendJsonResponse([
        'success' => false,
        'error' => 'Выбранный день не является рабочим'
    ], 400);
}

// Проверяем доступность слота
$allData = readJsonData(DATA_FILE);

if (!isSlotAvailable($allData, $date, $time)) {
    sendJsonResponse([
        'success' => false,
        'error' => 'Выбранное время уже занято или заблокировано. Пожалуйста, выберите другое время.'
    ], 400);
}

// Создаем запись
$success = atomicJsonUpdate(DATA_FILE, function($data) use ($name, $phone, $email, $telegram, $date, $time, $socialLinks, $competitorLinks, $problem) {
    // Генерируем ID
    $id = generateId($data['bookings']);

    $booking = [
        'id' => $id,
        'date' => $date,
        'time' => $time,
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
        'telegram' => $telegram,
        'socialLinks' => $socialLinks,
        'competitorLinks' => $competitorLinks,
        'problem' => $problem,
        'created_at' => date('c'),
        'status' => 'confirmed'
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

// Отправляем уведомление в Telegram
$telegramMessage = "🆕 <b>Новая запись на диагностику</b>\n\n";
$telegramMessage .= "📅 <b>Дата:</b> " . date('d.m.Y', strtotime($date)) . "\n";
$telegramMessage .= "🕐 <b>Время:</b> $time\n\n";
$telegramMessage .= "👤 <b>Имя:</b> $name\n";
$telegramMessage .= "💬 <b>Telegram:</b> $telegram\n";
if (!empty($phone)) {
    $telegramMessage .= "📱 <b>Телефон:</b> $phone\n";
}
if (!empty($email)) {
    $telegramMessage .= "📧 <b>Email:</b> $email\n";
}
$telegramMessage .= "\n🔗 <b>Ссылки на соцсети/сайт:</b>\n" . htmlspecialchars($socialLinks) . "\n\n";
$telegramMessage .= "🎯 <b>Ссылки на конкурентов:</b>\n" . htmlspecialchars($competitorLinks) . "\n\n";
$telegramMessage .= "📝 <b>Проблема:</b>\n" . htmlspecialchars($problem) . "\n\n";
$telegramMessage .= "⏰ <b>Создано:</b> " . date('d.m.Y H:i');

sendTelegramNotification($telegramMessage);

// Возвращаем успешный ответ
sendJsonResponse([
    'success' => true,
    'message' => 'Ваша заявка успешно отправлена! Мы свяжемся с вами в ближайшее время.'
]);
