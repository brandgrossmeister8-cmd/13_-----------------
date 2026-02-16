# Настройка автоматического деплоя на Beget

## Шаг 1: Подключение по SSH к Beget

1. Войдите в панель управления Beget
2. Перейдите в раздел **SSH**
3. Включите SSH-доступ (если не включён)
4. Подключитесь через терминал:
   ```
   ssh логин@логин.beget.tech
   ```

## Шаг 2: Клонирование репозитория на сервер

В SSH выполните команды:

```bash
# Перейдите в папку сайта
cd ~/ваш-сайт.ru/public_html

# Если папка не пустая, сохраните важные файлы (config.php, data/)
# и очистите папку

# Клонируйте репозиторий
git clone https://github.com/brandgrossmeister8-cmd/13_-----------------.git .
```

## Шаг 3: Настройка секретного ключа

1. Придумайте секретный ключ (например: `MySecretKey2024!`)
2. Откройте файл `deploy.php` на сервере
3. Замените `YOUR_SECRET_KEY_HERE` на ваш ключ:
   ```php
   $secret = 'MySecretKey2024!';
   ```

## Шаг 4: Настройка Webhook на GitHub

1. Откройте репозиторий на GitHub
2. Перейдите: **Settings** → **Webhooks** → **Add webhook**
3. Заполните:
   - **Payload URL:** `https://ваш-сайт.ru/deploy.php`
   - **Content type:** `application/json`
   - **Secret:** ваш секретный ключ (тот же, что в deploy.php)
   - **Which events:** Just the push event
4. Нажмите **Add webhook**

## Шаг 5: Проверка

1. Сделайте любое изменение в коде
2. Закоммитьте и запушьте:
   ```
   git add .
   git commit -m "Test deploy"
   git push
   ```
3. Проверьте сайт — изменения должны появиться автоматически
4. Проверьте лог: `https://ваш-сайт.ru/deploy.log`

## Безопасность

После настройки добавьте в `.htaccess`:

```apache
# Защита лог-файла деплоя
<Files "deploy.log">
    Order allow,deny
    Deny from all
</Files>
```

## Ручной деплой (если нужно)

Подключитесь по SSH и выполните:
```bash
cd ~/ваш-сайт.ru/public_html
git pull origin main
```
