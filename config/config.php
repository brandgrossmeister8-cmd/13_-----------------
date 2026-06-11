<?php
/**
 * Конфигурация админ-панели
 *
 * Серверные секреты и переопределения (прокси, токены) кладите в config.local.php
 * рядом с этим файлом — он в .gitignore и НЕ перезаписывается деплоем.
 * Значения из config.local.php имеют приоритет (подключается первым).
 */

// Локальные переопределения (только на сервере, не в git)
@include __DIR__ . '/config.local.php';

// Пароль администратора (хеш bcrypt)
// Для генерации нового хеша выполните: php -r "echo password_hash('ваш_пароль', PASSWORD_BCRYPT);"
if (!defined('ADMIN_PASSWORD_HASH')) define('ADMIN_PASSWORD_HASH', '$2y$10$HqF8w.vMmQDxu1P20rdasOC5XsrJriY12vV6eHR039pSEKEeFRNeq');

// Telegram Bot Token (если нужна интеграция с Telegram)
// Получить можно у @BotFather в Telegram
if (!defined('TELEGRAM_BOT_TOKEN')) define('TELEGRAM_BOT_TOKEN', '8107804993:AAGdmEXHBGNP365ZLZTPNKCzIiFwcfikX1E');

// Telegram Chat ID (куда отправлять уведомления)
// Узнать можно у @userinfobot
if (!defined('TELEGRAM_CHAT_ID')) define('TELEGRAM_CHAT_ID', '711863588');

// База Telegram Bot API. По умолчанию — официальный домен.
// Если хостинг блокирует api.telegram.org, в config.local.php можно задать
// IP Telegram + TELEGRAM_API_HOST + HTTP-прокси (TELEGRAM_PROXY / TELEGRAM_PROXY_AUTH).
if (!defined('TELEGRAM_API_BASE')) define('TELEGRAM_API_BASE', 'https://api.telegram.org');

// Заголовок Host (нужен, если TELEGRAM_API_BASE указывает на IP вместо имени).
// По умолчанию пусто — обычное поведение.
if (!defined('TELEGRAM_API_HOST')) define('TELEGRAM_API_HOST', '');

// HTTP-прокси для обхода блокировки Telegram (формат IP:PORT). Пусто = без прокси.
if (!defined('TELEGRAM_PROXY')) define('TELEGRAM_PROXY', '');
// Авторизация прокси (формат login:password). Пусто = без авторизации.
if (!defined('TELEGRAM_PROXY_AUTH')) define('TELEGRAM_PROXY_AUTH', '');

// Принудительно использовать IPv6 при подключении к Telegram API.
if (!defined('TELEGRAM_FORCE_IPV6')) define('TELEGRAM_FORCE_IPV6', false);

// Путь к файлу с данными
if (!defined('DATA_FILE')) define('DATA_FILE', __DIR__ . '/../data/bookings.json');

// Рабочие часы (время работы)
if (!defined('WORKING_HOURS')) define('WORKING_HOURS', [
    '10:00', '11:00', '12:00', '13:00',
    '14:00', '15:00', '16:00', '17:00'
]);

// Минутные интервалы внутри часа для консультаций.
// Каждый рабочий час делится на 3 слота по 20 минут: :00, :20, :40
if (!defined('CONSULTATION_INTERVALS')) define('CONSULTATION_INTERVALS', ['00', '20', '40']);

// Рабочие дни (1 = понедельник, 7 = воскресенье)
if (!defined('WORKING_DAYS')) define('WORKING_DAYS', [1, 2, 3, 4, 5]); // Пн-Пт

// Часовой пояс
date_default_timezone_set('Europe/Moscow');

// Настройки сессии
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');
