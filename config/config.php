<?php
/**
 * Конфигурация админ-панели
 */

// Пароль администратора (хеш bcrypt)
// Для генерации нового хеша выполните: php -r "echo password_hash('ваш_пароль', PASSWORD_BCRYPT);"
define('ADMIN_PASSWORD_HASH', '$2y$10$HqF8w.vMmQDxu1P20rdasOC5XsrJriY12vV6eHR039pSEKEeFRNeq');

// Telegram Bot Token (если нужна интеграция с Telegram)
// Получить можно у @BotFather в Telegram
define('TELEGRAM_BOT_TOKEN', '8107804993:AAGdmEXHBGNP365ZLZTPNKCzIiFwcfikX1E');

// Telegram Chat ID (куда отправлять уведомления)
// Узнать можно у @userinfobot
define('TELEGRAM_CHAT_ID', '711863588');

// База Telegram Bot API. По умолчанию — официальный домен.
// Если ваш хостинг блокирует api.telegram.org (типично для российских VPS),
// поднимите Cloudflare Worker как прокси (см. CLOUDFLARE_SETUP.md) и
// замените эту строку на URL вашего Worker-а, например:
//   define('TELEGRAM_API_BASE', 'https://tg-proxy.YOURNAME.workers.dev');
define('TELEGRAM_API_BASE', 'https://api.telegram.org');

// Принудительно использовать IPv6 при подключении к Telegram API.
// Иногда IPv4-маршрут блокируется, а IPv6 работает. Если не уверены — false.
define('TELEGRAM_FORCE_IPV6', false);

// Путь к файлу с данными
define('DATA_FILE', __DIR__ . '/../data/bookings.json');

// Рабочие часы (время работы)
define('WORKING_HOURS', [
    '10:00', '11:00', '12:00', '13:00',
    '14:00', '15:00', '16:00', '17:00'
]);

// Рабочие дни (1 = понедельник, 7 = воскресенье)
define('WORKING_DAYS', [1, 2, 3, 4, 5]); // Пн-Пт

// Часовой пояс
date_default_timezone_set('Europe/Moscow');

// Настройки сессии
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');
