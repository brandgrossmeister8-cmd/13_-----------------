#!/bin/bash
# Установка cron-задания для ежечасного бэкапа bookings.json.
# Идемпотентно: повторный запуск не задвоит запись.

set -e

CRON_LINE="0 * * * * /var/www/formareg.brandgrossmeister.ru/backup_bookings.sh"

# Берём текущий crontab (если есть), убираем старую запись про backup_bookings,
# добавляем свежую — и устанавливаем обратно.
( crontab -l 2>/dev/null | grep -v backup_bookings.sh ; echo "$CRON_LINE" ) | crontab -

echo "Cron установлен. Текущий crontab:"
crontab -l
