#!/bin/bash
# Установка cron-задания для ежечасного бэкапа bookings.json.
# Идемпотентно: повторный запуск не задвоит запись.

CRON_LINE="0 * * * * /var/www/formareg.brandgrossmeister.ru/backup_bookings.sh"
TMP=/tmp/_install_cron.tmp

# 1. Текущий crontab без наших старых записей
crontab -l 2>/dev/null | grep -v backup_bookings.sh > "$TMP" || true

# 2. Добавляем свежую запись
echo "$CRON_LINE" >> "$TMP"

echo "=== Будет установлено в crontab: ==="
cat "$TMP"
echo "=== ==="
echo ""

# 3. Устанавливаем
crontab "$TMP"
RC=$?

if [ $RC -ne 0 ]; then
    echo "❌ Ошибка установки crontab (код $RC)"
    exit $RC
fi

echo "=== После установки (crontab -l): ==="
crontab -l
echo "=== ==="
echo ""
echo "✅ Cron установлен. Бэкап будет создаваться каждый час в /var/backups/formareg/"

rm -f "$TMP"
