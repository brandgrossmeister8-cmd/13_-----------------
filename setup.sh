#!/bin/bash
# setup.sh — полная настройка и проверка сайта одной командой.
# Безопасен для повторного запуска (идемпотентен).
#
# Запуск:
#   bash /var/www/formareg.brandgrossmeister.ru/setup.sh

REPO_DIR="/var/www/formareg.brandgrossmeister.ru"
TS="$(date +%Y%m%d-%H%M%S)"
BACKUP="/tmp/bookings.backup.$TS.json"

cd "$REPO_DIR" 2>/dev/null || { echo "❌ Не зашёл в $REPO_DIR"; exit 1; }

# Цвета (если поддерживаются)
if [ -t 1 ]; then
    G="\033[32m"; R="\033[31m"; Y="\033[33m"; B="\033[1m"; N="\033[0m"
else
    G=""; R=""; Y=""; B=""; N=""
fi

ok()    { echo -e "${G}✓${N} $1"; }
warn()  { echo -e "${Y}!${N} $1"; }
fail()  { echo -e "${R}✗${N} $1"; }
title() { echo ""; echo -e "${B}── $1 ──${N}"; }

echo "════════════════════════════════════════════"
echo " setup.sh — полная настройка"
echo " $(date)"
echo "════════════════════════════════════════════"

title "1. Бэкап data/bookings.json"
if [ -f data/bookings.json ]; then
    cp data/bookings.json "$BACKUP" && ok "$BACKUP ($(wc -c < "$BACKUP") байт)"
else
    BACKUP=""; warn "data/bookings.json не найден — пропускаю"
fi

title "2. Стэш локальных изменений"
if [ -n "$(git status --porcelain)" ]; then
    git stash push -u -m "setup-$TS" >/dev/null && ok "Стэш создан"
else
    ok "Локальных изменений нет"
fi

title "3. git pull origin main"
git pull origin main 2>&1 | sed 's/^/  /'

title "4. Восстановление data/bookings.json"
if [ -n "$BACKUP" ] && [ -f "$BACKUP" ]; then
    cp "$BACKUP" data/bookings.json && ok "Данные на месте"
else
    warn "Бэкапа нет, пропустил"
fi

title "5. Права на data/ (www-data)"
chown -R www-data:www-data data/ 2>/dev/null && ok "chown www-data" || fail "chown упал"
chmod -R u+rwX,g+rwX data/ 2>/dev/null && ok "chmod ok" || fail "chmod упал"

title "6. Скрипты исполняемые"
chmod +x backup_bookings.sh install_cron.sh setup.sh obnovi.sh 2>/dev/null && ok "ok"

title "7. Установка cron (бэкап раз в час)"
CRON_LINE="0 * * * * $REPO_DIR/backup_bookings.sh"
TMP_CRON=/tmp/_cron.$TS
crontab -l 2>/dev/null | grep -v backup_bookings.sh > "$TMP_CRON" || true
echo "$CRON_LINE" >> "$TMP_CRON"
crontab "$TMP_CRON" && ok "Cron установлен" || fail "Cron не установился"
rm -f "$TMP_CRON"

title "8. Тестовый бэкап прямо сейчас"
bash backup_bookings.sh && ok "Готово"
ls -1t /var/backups/formareg/bookings-*.json 2>/dev/null | head -3 | sed 's/^/  /'

title "9. Последние 5 коммитов"
git log --oneline -5 | sed 's/^/  /'

title "10. Ключевые файлы"
for f in api/diag.php api/helpers.php api/bookings.php send.php data/bookings.json data/.htaccess; do
    if [ -e "$f" ]; then
        ok "$f ($(wc -c < "$f") байт)"
    else
        fail "$f отсутствует"
    fi
done

title "11. Заявок в JSON"
COUNT=$(grep -c '"id":' data/bookings.json 2>/dev/null || echo 0)
ok "$COUNT записей"

title "12. nginx error log (последние 15 строк)"
tail -15 /var/log/nginx/error.log 2>&1 | sed 's/^/  /' || warn "(нет доступа к логу)"

title "13. PHP-FPM error log (последние 15 строк)"
tail -15 /var/log/php8.3-fpm.log 2>&1 | sed 's/^/  /' || warn "(нет файла)"

title "14. Telegram log (последние 10 строк)"
if [ -f data/telegram.log ]; then
    tail -10 data/telegram.log | sed 's/^/  /'
else
    ok "Файла нет — значит ошибок Telegram не было"
fi

title "15. Текущий crontab"
crontab -l 2>/dev/null | sed 's/^/  /' || warn "(пусто)"

echo ""
echo "════════════════════════════════════════════"
echo " ✅ Готово."
echo "════════════════════════════════════════════"
