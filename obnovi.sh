#!/bin/bash
# Безопасное обновление сайта из GitHub
# - бэкапит data/ перед pull
# - стэшит локальные изменения, чтобы pull всегда проходил
# - восстанавливает data/ из бэкапа после pull
# - выводит диагностику в конце

set -e

REPO_DIR="/var/www/formareg.brandgrossmeister.ru"
BACKUP_DIR="/tmp"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"

cd "$REPO_DIR"

echo "=== [1/5] Бэкап data/bookings.json ==="
BACKUP="$BACKUP_DIR/bookings.backup.$TIMESTAMP.json"
if [ -f data/bookings.json ]; then
    cp data/bookings.json "$BACKUP"
    echo "Бэкап: $BACKUP ($(wc -c < "$BACKUP") байт)"
else
    BACKUP=""
    echo "Файл data/bookings.json не найден — пропускаю бэкап"
fi

echo ""
echo "=== [2/5] Стэш локальных изменений (если есть) ==="
if [ -n "$(git status --porcelain)" ]; then
    git stash push -u -m "auto-stash-$TIMESTAMP"
    STASHED=1
else
    STASHED=0
    echo "Нет локальных изменений"
fi

echo ""
echo "=== [3/5] git pull origin main ==="
git pull origin main

echo ""
echo "=== [4/5] Восстановление data/bookings.json из бэкапа ==="
if [ -n "$BACKUP" ] && [ -f "$BACKUP" ]; then
    cp "$BACKUP" data/bookings.json
    echo "Восстановлен из $BACKUP"
else
    echo "Нечего восстанавливать"
fi

echo ""
echo "=== [5/5] Диагностика ==="
git log --oneline -3
echo ""
echo "Файлы на месте:"
ls -la data/bookings.json api/diag.php api/helpers.php 2>&1

if [ "$STASHED" = "1" ]; then
    echo ""
    echo "ℹ️  Локальные изменения остались в стэше — посмотреть: git stash list"
fi

echo ""
echo "✅ Сайт обновлён!"
