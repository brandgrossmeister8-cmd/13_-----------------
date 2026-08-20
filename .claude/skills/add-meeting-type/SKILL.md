---
name: add-meeting-type
description: Добавить новый тип встречи (кнопку-карточку) в форму записи formareg. Используй, когда просят добавить новую услугу/тип встречи, новую кнопку выбора типа, или изменить набор полей/цену/правила для типа встречи.
---

# Добавление нового типа встречи в форму записи

Форма записи (`index.html` + `send.php` + `api/helpers.php` + `api/availability.php`)
поддерживает несколько типов встреч. Сейчас их три:

| Ключ (`data-type`) | Название | Длительность | Слоты | Особенности полей |
|---|---|---|---|---|
| `consultation` | Экспресс-консультация | 15 мин | 20-мин ячейки (под-слоты) | только `website`; **только собственник** |
| `diagnostic` | Диагностика | 60 мин | полный час | `socialLinks` + `competitorLinks` |
| `general` | Консультация | 60 мин | полный час | `socialLinks` (без конкурентов) |

Ключевой принцип бэкенда: **всё, что не `consultation`, обрабатывается как «диагностика»
(полный час)**. Поэтому новый 60-минутный тип почти всё получает автоматически.

## Модель полей (что показывается для какого типа)

Видимость условных полей задаётся атрибутом `data-for` на элементах с классом `.type-field`.
JS в `selectMeetingType()` показывает элемент, если выбранный тип есть в списке `data-for`.

- `#websiteGroup` — `data-for="consultation"`
- `#socialLinksGroup` — `data-for="diagnostic general"`
- `#competitorLinksGroup` — `data-for="diagnostic"`
- `#diagnosticInfoBlock` — `data-for="diagnostic"`
- подсказка «только собственник» у роли — `data-for="consultation"` (правило только для бесплатной экспресс-консультации)

Общие поля (имя, телефон, способ связи, роль, возраст бизнеса, страна/город, «какой бизнес», проблема) показываются для всех типов.

## Чек-лист: добавить новый тип `NEWKEY`

### Фронтенд (`index.html`)

1. **Карточка.** В блок `.meeting-type-options` добавь карточку (скопируй существующую):
   ```html
   <div class="meeting-type-option collapsed" role="button" tabindex="0" data-type="NEWKEY">
       <span class="meeting-type-head">
           <span class="meeting-type-title">4. Название</span>
           <span class="meeting-type-sub">ЦЕНА · 60 минут</span>
       </span>
       <span class="meeting-type-desc">
           <span class="mt-block"><span class="mt-block-label">Длительность</span><span class="mt-block-text">60 минут</span></span>
           <span class="mt-block"><span class="mt-block-label">Стоимость</span><span class="mt-block-text">...</span></span>
           <span class="mt-block"><span class="mt-block-label">О чём</span><span class="mt-block-text">...</span></span>
       </span>
   </div>
   ```
   Класс `collapsed` обязателен — карточки стартуют свёрнутыми (кнопка), раскрываются по клику.

2. **Видимость полей.** Добавь `NEWKEY` в `data-for` тех `.type-field`, которые должны показываться (например, `data-for="diagnostic general NEWKEY"` на `#socialLinksGroup`).

3. **Сброс ошибок скрытых полей.** В `selectMeetingType()` в объекте `visibleByType` добавь строку `NEWKEY: [...]` — перечисли, какие из `website`/`socialLinks`/`competitorLinks` видимы для нового типа.

4. **Слоты/календарь.** Если новый тип на **60 минут (полный час)** — ничего менять не нужно: `loadUserCalendarData`/`loadUserTimeSlots` мапят `consultation → consultation, иначе → diagnostic`, а `renderTimeSlots` рисует «диагностический» вид при `selectedType !== 'consultation'`. Если тип на **15 минут (под-слоты)** — нужна отдельная логика как у `consultation`.

5. **Клиентская валидация** (функция отправки формы): проверь блоки для `socialLinks` (`if (selectedType !== 'consultation')`) и `competitorLinks` (`if (selectedType === 'diagnostic')`) — при необходимости добавь/расширь условия под новый тип. Правило «только собственник» (`selectedType === 'consultation'`) не трогай, если новый тип платный.

6. **Подпись в админке.** В `renderAdminBookings` (переменная `typeLabel`) добавь ветку для `NEWKEY`.

7. **Текст-лид** формы (`.form-lead`) при желании упомяни новый тип.

### Бэкенд

8. **`send.php`:** добавь `NEWKEY` в белый список типов (строка `$type = in_array($typeInput, ['consultation','diagnostic','general'], true) ? ...`). Проверь правила required-полей: `socialLinks` (`$type !== 'consultation'`), `competitorLinks` (`$type === 'diagnostic'`), роль-собственник (`$type === 'consultation'`).

9. **`api/helpers.php`:** в `bookingTypeLabel()` добавь ветку для `NEWKEY` (подпись для Telegram/админки). `getBookingType()` для любого не-`consultation` вернёт `diagnostic` (полный час) — менять не нужно.

10. **`api/availability.php`:** для 60-мин типов менять не нужно (whitelist приводит неизвестный тип к `diagnostic`, логика слотов та же).

### Деплой (ОБЯЗАТЕЛЬНО)

11. **Подними версию синхронно** в двух местах (защита от кеша, см. [[formareg-version-bump]]):
    - `var BUILD = 'ГГГГ-ММ-ДД.N'` в `<head>` `index.html`
    - содержимое `version.txt`

12. Проверь синтаксис JS (извлечь `<script>` → `node --check`), затем `git add`, `git commit`, `git push`. Деплой по вебхуку ~30 сек.

13. **Проверь на боевом** (не создавая реальных записей): `curl version.txt` (новая версия), наличие карточки в `index.html`, и валидацию `send.php` через curl с заведомо неверным временем (`"time":"99:99"`) — тогда запись не создаётся, но видно, какие поля обязательны:
    ```bash
    curl -s -X POST -H 'Content-Type: application/json' \
      -d '{"type":"NEWKEY","name":"Тест","phone":"+7(999)111-22-33","preferredContact":["telegram"],"telegram":"@testuser","date":"25.08.2026","time":"99:99","businessRole":"employee","businessAge":"1_3","country":"РФ","city":"М","activity":"т","socialLinks":"vk.com/t","problem":"т"}' \
      https://formareg.brandgrossmeister.ru/send.php
    ```
    В ответе `errors` должны быть только ожидаемые для нового типа.

## Изменить цену/описание/правила существующего типа

- Цена/описание — в его карточке (`data-type="..."`): `meeting-type-sub` и блоки `mt-block`.
- Обязательность полей — правила в `send.php` (сервер) и в функции валидации формы (клиент), синхронно.
- «Только собственник» — правило завязано на `selectedType === 'consultation'` / `$type === 'consultation'`. Меняешь тут — меняй и клиент, и сервер.
- После любой правки `index.html` — подними версию (`BUILD` + `version.txt`).
