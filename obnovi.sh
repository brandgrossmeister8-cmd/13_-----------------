#!/bin/bash
# Скрипт обновления сайта из GitHub

cd /var/www/formareg.brandgrossmeister.ru
git pull origin main
echo "Сайт обновлён!"
