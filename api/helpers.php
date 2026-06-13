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

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }

    try {
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

        // Если callback бросает исключение — финалли всё равно отпустит lock
        $data = $callback($data);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

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
 * Валидация времени с учётом типа записи.
 * Для диагностики — это рабочий час (HH:00).
 * Для консультации — 20-минутный интервал (HH:MM, MM ∈ 00/20/40) внутри рабочего часа.
 * @param string $time
 * @param string $type 'diagnostic' | 'consultation'
 * @return bool
 */
function validateTimeForType($time, $type) {
    if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $m)) {
        return false;
    }
    if ($type === 'consultation') {
        $hour = $m[1] . ':00';
        return in_array($hour, WORKING_HOURS, true)
            && in_array($m[2], CONSULTATION_INTERVALS, true);
    }
    return in_array($time, WORKING_HOURS, true);
}

/**
 * Нормализация типа записи. Старые записи без поля type — это часовые диагностики.
 * @param array $booking
 * @return string 'diagnostic' | 'consultation'
 */
function getBookingType($booking) {
    return (($booking['type'] ?? 'diagnostic') === 'consultation') ? 'consultation' : 'diagnostic';
}

/**
 * Час, к которому относится время: "10:15" -> "10:00"
 * @param string $time
 * @return string
 */
function getHourOf($time) {
    return substr($time, 0, 2) . ':00';
}

/**
 * 20-минутные интервалы внутри часа: "10:00" -> ["10:00","10:20","10:40"]
 * @param string $hour
 * @return array
 */
function getHourSubSlots($hour) {
    $h = substr($hour, 0, 2);
    $subs = [];
    foreach (CONSULTATION_INTERVALS as $mm) {
        $subs[] = $h . ':' . $mm;
    }
    return $subs;
}

/**
 * Область блокировки слота: 'hour' (весь час) или 'slot' (один 20-мин интервал).
 * Старые записи без поля scope считаются блокировкой часа (обратная совместимость).
 * @param array $blocked
 * @return string 'hour' | 'slot'
 */
function getBlockScope($blocked) {
    return (($blocked['scope'] ?? 'hour') === 'slot') ? 'slot' : 'hour';
}

/**
 * Заблокирован ли час целиком (блокировка всего дня или блокировка всего часа админом).
 * Поблочные (20-мин) блокировки сюда НЕ входят — они проверяются отдельно.
 * @param array $allData
 * @param string $date
 * @param string $hour
 * @return bool
 */
function isHourBlocked($allData, $date, $hour) {
    foreach ($allData['blocked_dates'] as $blocked) {
        if ($blocked['date'] === $date && !empty($blocked['all_day'])) {
            return true;
        }
    }
    foreach ($allData['blocked_slots'] as $blocked) {
        if ($blocked['date'] === $date
            && getBlockScope($blocked) === 'hour'
            && getHourOf($blocked['time']) === $hour) {
            return true;
        }
    }
    return false;
}

/**
 * Заблокирован ли конкретный 20-мин интервал (точечная блокировка scope=slot).
 * @param array $allData
 * @param string $date
 * @param string $subTime  Время интервала, напр. "10:20"
 * @return bool
 */
function isSubSlotBlocked($allData, $date, $subTime) {
    foreach ($allData['blocked_slots'] as $blocked) {
        if ($blocked['date'] === $date
            && getBlockScope($blocked) === 'slot'
            && $blocked['time'] === $subTime) {
            return true;
        }
    }
    return false;
}

/**
 * Полная информация о часе: статус, существующие брони и доступность под каждый тип.
 *
 * Правила:
 *  - Диагностика (1 час) возможна, только если час полностью свободен (нет консультаций и нет диагностики).
 *  - Консультация (20 мин) возможна, если час не занят диагностикой и заняты не все интервалы.
 *
 * status: blocked | diagnostic_booked | free | partial | full
 *  - free    — час полностью свободен (зелёный)
 *  - partial — заняты 1–3 интервала консультациями (сиреневый): диагностику поставить нельзя,
 *              но свободные 20-мин интервалы ещё можно выбрать
 *  - full    — заняты все интервалы часа (красный)
 *
 * @param array $allData
 * @param string $date
 * @param string $hour
 * @return array
 */
function getHourInfo($allData, $date, $hour) {
    $blocked = isHourBlocked($allData, $date, $hour);

    $diagnosticBooked = false;
    $consultations = [];
    foreach ($allData['bookings'] as $booking) {
        if ($booking['date'] !== $date) {
            continue;
        }
        if (getBookingType($booking) === 'diagnostic') {
            if ($booking['time'] === $hour) {
                $diagnosticBooked = true;
            }
        } else {
            if (getHourOf($booking['time']) === $hour) {
                $consultations[] = $booking['time'];
            }
        }
    }

    $count = count($consultations);

    // Собираем интервалы с учётом броней и точечных блокировок
    $subSlots = [];
    $freeSubs = 0;
    $anySubBlocked = false;
    foreach (getHourSubSlots($hour) as $sub) {
        $subBooked = in_array($sub, $consultations, true);
        $subBlocked = isSubSlotBlocked($allData, $date, $sub);
        if ($subBlocked) {
            $anySubBlocked = true;
        }
        $available = !$blocked && !$diagnosticBooked && !$subBooked && !$subBlocked;
        if ($available) {
            $freeSubs++;
        }
        $subSlots[] = [
            'time' => $sub,
            'available' => $available,
            'booked' => $subBooked,
            'blocked' => $subBlocked
        ];
    }

    $totalSubs = count(getHourSubSlots($hour));

    if ($blocked) {
        $status = 'blocked';
    } elseif ($diagnosticBooked) {
        $status = 'diagnostic_booked';
    } elseif ($freeSubs === $totalSubs) {
        $status = 'free';
    } elseif ($freeSubs === 0) {
        $status = 'full';
    } else {
        $status = 'partial';
    }

    // Диагностика — только если весь час полностью свободен (нет броней и нет точечных блокировок)
    $diagnosticAvailable = !$blocked && !$diagnosticBooked && $count === 0 && !$anySubBlocked;
    // Консультация — если есть хотя бы один свободный интервал
    $consultationAvailable = !$blocked && !$diagnosticBooked && $freeSubs > 0;

    return [
        'hour' => $hour,
        'blocked' => $blocked,
        'diagnosticBooked' => $diagnosticBooked,
        'consultations' => $consultations,
        'count' => $count,
        'status' => $status,
        'diagnosticAvailable' => $diagnosticAvailable,
        'consultationAvailable' => $consultationAvailable,
        'subSlots' => $subSlots
    ];
}

/**
 * Доступен ли конкретный слот под конкретный тип записи.
 * @param array $allData
 * @param string $date
 * @param string $time Для диагностики — час (HH:00), для консультации — интервал (HH:MM)
 * @param string $type 'diagnostic' | 'consultation'
 * @return bool
 */
function isSlotAvailableForType($allData, $date, $time, $type) {
    if ($type === 'consultation') {
        $info = getHourInfo($allData, $date, getHourOf($time));
        if (!$info['consultationAvailable']) {
            return false;
        }
        foreach ($info['subSlots'] as $s) {
            if ($s['time'] === $time) {
                return $s['available'];
            }
        }
        return false;
    }
    return getHourInfo($allData, $date, $time)['diagnosticAvailable'];
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
 * Получение статуса дня (free/partial/full/blocked) с учётом типа записи.
 * День считается доступным (free/partial), если есть хотя бы один час,
 * куда можно поставить запись выбранного типа.
 * @param array $allData Все данные
 * @param string $date Дата
 * @param string $type 'diagnostic' | 'consultation'
 * @return array ['status' => string, 'available_slots' => array, 'total_slots' => int]
 */
function getDayStatus($allData, $date, $type = 'diagnostic') {
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

    foreach (WORKING_HOURS as $hour) {
        $info = getHourInfo($allData, $date, $hour);
        $ok = ($type === 'consultation') ? $info['consultationAvailable'] : $info['diagnosticAvailable'];
        if ($ok) {
            $availableSlots[] = $hour;
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
 * Применяет к curl-хендлу настройки прокси и заголовок Host для обхода
 * блокировки Telegram на хостинге. Значения берутся из config.php на сервере
 * (TELEGRAM_PROXY / TELEGRAM_PROXY_AUTH / TELEGRAM_API_HOST). В репозитории
 * их нет — поэтому при отсутствии констант поведение не меняется.
 * @param resource|CurlHandle $ch
 */
function applyTelegramProxyOpts($ch) {
    if (defined('TELEGRAM_PROXY') && TELEGRAM_PROXY) {
        curl_setopt($ch, CURLOPT_PROXY, TELEGRAM_PROXY);
        if (defined('TELEGRAM_PROXY_AUTH') && TELEGRAM_PROXY_AUTH) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, TELEGRAM_PROXY_AUTH);
        }
    }
    // Если базовый адрес — это IP (обход блокировки по имени/SNI), задаём правильный Host
    if (defined('TELEGRAM_API_HOST') && TELEGRAM_API_HOST) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: ' . TELEGRAM_API_HOST]);
    }
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

    $apiBase = defined('TELEGRAM_API_BASE') ? rtrim(TELEGRAM_API_BASE, '/') : 'https://api.telegram.org';
    $url = $apiBase . "/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";

    $data = [
        'chat_id' => TELEGRAM_CHAT_ID,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];

    // 3 попытки с backoff: связь до api.telegram.org с российских хостингов
    // часто прерывистая — одна попытка часто не проходит, вторая/третья — да.
    $attempts = 3;
    $backoffSeconds = [0, 1, 2]; // задержка ПЕРЕД попыткой 1, 2, 3
    $lastResponse = false;
    $lastHttpCode = 0;
    $lastCurlError = '';
    $tryHistory = [];

    for ($i = 0; $i < $attempts; $i++) {
        if ($backoffSeconds[$i] > 0) {
            sleep($backoffSeconds[$i]);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        applyTelegramProxyOpts($ch);
        if (defined('TELEGRAM_FORCE_IPV6') && TELEGRAM_FORCE_IPV6) {
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V6);
        }

        $lastResponse = curl_exec($ch);
        $lastHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $lastCurlError = curl_error($ch);
        curl_close($ch);

        $tryHistory[] = "try " . ($i + 1) . ": http=" . $lastHttpCode . ($lastCurlError ? " curl=" . $lastCurlError : "");

        if ($lastResponse !== false && $lastHttpCode === 200) {
            break; // получилось — не делаем ещё попыток
        }
    }

    $ok = ($lastResponse !== false && $lastHttpCode === 200);
    $errorMsg = '';
    if (!$ok) {
        if ($lastCurlError) {
            $errorMsg = 'cURL: ' . $lastCurlError;
        } elseif ($lastHttpCode !== 200) {
            $errorMsg = 'HTTP ' . $lastHttpCode . ': ' . substr((string)$lastResponse, 0, 500);
        } else {
            $errorMsg = 'unknown';
        }
        $errorMsg .= ' [' . implode('; ', $tryHistory) . ']';
    }

    $result = [
        'ok' => $ok,
        'http_code' => $lastHttpCode,
        'response' => is_string($lastResponse) ? $lastResponse : '',
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
    $isConsultation = (getBookingType($booking) === 'consultation');
    $typeLabel = $isConsultation ? 'Экспресс-консультация (20 мин)' : 'Диагностика (1 час)';
    if ($isRetry) {
        $title = "🔁 <b>Доставка после сбоя — новая запись</b>";
    } else {
        $title = "🆕 <b>Новая запись: " . $typeLabel . "</b>";
    }
    $msg  = $title . "\n\n";
    $msg .= "🧩 <b>Тип:</b> " . $typeLabel . "\n";
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
    if (!empty($booking['vk'])) {
        $msg .= "🟦 <b>ВКонтакте:</b> " . htmlspecialchars($booking['vk']) . "\n";
    }
    if (!empty($booking['max'])) {
        $msg .= "🅼 <b>MAX:</b> " . htmlspecialchars($booking['max']) . "\n";
    }
    if (!empty($booking['preferredContact'])) {
        $preferredMap = [
            'telegram' => 'Telegram',
            'email' => 'Email',
            'vk' => 'ВКонтакте',
            'max' => 'MAX'
        ];
        $items = is_array($booking['preferredContact']) ? $booking['preferredContact'] : [$booking['preferredContact']];
        $labels = [];
        foreach ($items as $it) {
            $labels[] = $preferredMap[$it] ?? $it;
        }
        if (!empty($labels)) {
            $msg .= "⭐ <b>Связь для ссылки Zoom:</b> " . htmlspecialchars(implode(', ', $labels)) . "\n";
        }
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
    if (!empty($booking['utm']) && is_array($booking['utm'])) {
        $utm = $booking['utm'];
        $utmLabels = [
            'utm_source'   => 'источник',
            'utm_medium'   => 'канал',
            'utm_campaign' => 'кампания',
            'utm_content'  => 'место',
            'utm_term'     => 'ключ'
        ];
        $parts = [];
        foreach ($utmLabels as $k => $label) {
            if (!empty($utm[$k])) {
                $parts[] = $label . ': ' . $utm[$k];
            }
        }
        if (!empty($parts)) {
            $msg .= "\n📈 <b>Источник (UTM):</b> " . htmlspecialchars(implode(', ', $parts)) . "\n";
        }
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
