# Установка Cloudflare Worker как прокси для Telegram

Зачем: Timeweb VPS периодически (или постоянно) теряет доступ к `api.telegram.org` — провайдер режет маршрут. Из-за этого уведомления формы не доходят. Cloudflare Worker — лёгкий прокси, который примет наш запрос и перешлёт в Telegram через свою сеть. Бесплатно, до 100 000 запросов в сутки (нам хватит на годы).

## Шаги (5–7 минут)

### 1. Регистрация в Cloudflare
- Открыть https://dash.cloudflare.com/sign-up
- Email + пароль. Карта не нужна.

### 2. Создать Worker
- В дашборде слева: **Workers & Pages** → **Create**.
- Если предложат выбрать тип — **Hello World** → **Deploy**.
- Можно выбрать любое имя, например `tg-proxy`. Запомнить.

### 3. Заменить код
- На странице созданного воркера: **Edit code**.
- В правой части (редактор) удалить весь код.
- Открыть файл `cloudflare-worker.js` из этого репозитория, **скопировать его целиком** и вставить.
- Нажать **Save and deploy** в правом верхнем углу.

### 4. Скопировать URL воркера
- После деплоя в шапке будет показан URL вида:
  `https://tg-proxy.ВАШ-АККАУНТ.workers.dev`
- Открыть его в браузере для проверки — должна вернуться надпись:
  `Telegram Bot API proxy. Use /bot<TOKEN>/<METHOD>`

### 5. Прописать URL в конфиге сайта
В файле `config/config.php` строка:
```php
define('TELEGRAM_API_BASE', 'https://api.telegram.org');
```
заменить на:
```php
define('TELEGRAM_API_BASE', 'https://tg-proxy.ВАШ-АККАУНТ.workers.dev');
```

Это можно сделать прямо на сервере одной командой (вместо `tg-proxy.YOUR.workers.dev` подставить ваш URL):

```
sed -i "s#TELEGRAM_API_BASE', 'https://api.telegram.org'#TELEGRAM_API_BASE', 'https://tg-proxy.YOUR.workers.dev'#" /var/www/formareg.brandgrossmeister.ru/config/config.php
```

### 6. Проверить
- В админке открыть `/api/diag.php` — поле `telegram_api_base` должно показывать новый URL, `getMe` и `sendMessage` — `ok: true`.
- В админке нажать «📤 Отправить в ТГ» на любой 🔴 заявке — должно прийти уведомление.

## Готово
Все будущие уведомления будут идти через ваш Worker — прерывистая связь Timeweb→Telegram больше не страшна.

## Безопасность
- Token бота **уже** ходил через api.telegram.org открытым (HTTPS); теперь он идёт через ваш собственный Worker (тоже HTTPS) — никто кроме Cloudflare и Telegram его не видит.
- Worker не логирует запросы. Если хотите параноидальнее — добавьте в worker проверку секрет-заголовка.
