#!/bin/bash
# Устанавливает два cron-задания:
#   1. Ежечасный бэкап data/bookings.json
#   2. Каждые 5 минут — попытка доотправить недоставленные Telegram-уведомления
#      (на случай если связь с api.telegram.org прерывистая)
#
# Идемпотентно: повторный запуск не задвоит записи.

REPO_DIR="/var/www/formareg.brandgrossmeister.ru"
BACKUP_LINE="0 * * * * $REPO_DIR/backup_bookings.sh"
RETRY_LINE="*/5 * * * * php $REPO_DIR/api/cron_retry.php >> /var/log/tg_retry.log 2>&1"
TMP=/tmp/_install_cron.tmp

# 1. Берём текущий crontab, удаляем наши старые записи
crontab -l 2>/dev/null | grep -v -E "backup_bookings.sh|cron_retry.php" > "$TMP" || true

# 2. Добавляем свежие
echo "$BACKUP_LINE" >> "$TMP"
echo "$RETRY_LINE" >> "$TMP"

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

# Создаём лог-файл, если его нет, и даём права www-data (на всякий случай — крон под root, файл создастся как root)
touch /var/log/tg_retry.log 2>/dev/null
chmod 644 /var/log/tg_retry.log 2>/dev/null

echo "=== После установки (crontab -l): ==="
crontab -l
echo "=== ==="
echo ""
echo "✅ Готово. Что теперь делает сервер сам:"
echo "  • Раз в час бэкапит data/bookings.json в /var/backups/formareg/"
echo "  • Каждые 5 минут пытается доотправить все недоставленные ТГ-уведомления."
echo "    Лог попыток: /var/log/tg_retry.log"

rm -f "$TMP"
