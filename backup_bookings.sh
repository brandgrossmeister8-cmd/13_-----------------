#!/bin/bash
# Ежечасный бэкап data/bookings.json
# Запускается cron-ом, хранит последние 168 копий (7 дней × 24 часа)

set -e

REPO_DIR="/var/www/formareg.brandgrossmeister.ru"
SRC="$REPO_DIR/data/bookings.json"
DST_DIR="/var/backups/formareg"
KEEP=168
TS="$(date +%Y%m%d-%H%M%S)"

# Создаём папку для бэкапов, если её нет
mkdir -p "$DST_DIR"

# Если исходник не существует — выходим без ошибки
if [ ! -f "$SRC" ]; then
    echo "[$(date)] $SRC не найден — пропускаю" >> "$DST_DIR/backup.log"
    exit 0
fi

# Бэкапим
DST="$DST_DIR/bookings-$TS.json"
cp "$SRC" "$DST"

# Удаляем старые, оставляем только последние $KEEP
ls -1t "$DST_DIR"/bookings-*.json 2>/dev/null | tail -n +$((KEEP + 1)) | xargs -r rm --

echo "[$(date)] Бэкап создан: $DST" >> "$DST_DIR/backup.log"
