# Flow Constructor UI — Хранение данных пользователя

**Документ:** UX/UI спецификация для Flow Constructor
**Версия:** 1.0
**Дата:** Апрель 2026
**Контекст:** FaPost Phase 2 — Flow Engine, Sprint 6 (Flow Constructor UI)
**Связанные документы:** Flow Engine Nodes V1, Platform Architecture v2.2

---

## 1. Принципы

### 1.1 Что видит пользователь конструктора

Контент-менеджер строящий flow оперирует **бытовыми концептами**:
- «Поле контакта» — данные которые сохранятся в профиле
- «Временно» — данные которые нужны только сейчас
- «Спросить пользователя» — нода ввода
- «Если...» — нода ветвления
- «Отправить сообщение» — нода ответа

### 1.2 Что НЕ должен видеть пользователь

В UI не используются технические термины:
- ❌ namespace
- ❌ session
- ❌ scope
- ❌ persistent / volatile
- ❌ attribute
- ❌ state
- ❌ flow.* / contact.*

Эти концепты живут только в JSON snapshot и runtime engine. Пользователь видит результат работы — данные сохранены или временные.

### 1.3 Принцип: один концепт переменной

У переменной есть:
1. **Имя** (как пользователь её называет)
2. **Тип** (текст, число, телефон, и т.д.)
3. **Где хранится** (в профиле или временно)

Все три — атрибуты одной переменной. Не разные сущности.

---

## 2. Хранение данных — модель

### 2.1 Два режима хранения

| Режим | Иконка | Что значит | Когда использовать |
|-------|--------|------------|---------------------|
| Contact profile | 💾 | Данные сохраняются в профиле контакта навсегда. Доступны во всех будущих flows. | Большинство случаев: имя, телефон, выбор тарифа, оценки в опросе |
| Temporary | ⏱ | Данные живут только в текущем диалоге. После завершения flow исчезают. | Одноразовые: код подтверждения SMS, параметры запуска (отдел для опроса) |

### 2.2 Дефолт — Contact profile

При создании ноды Input/Assign **по умолчанию** выбран режим Contact profile. Это покрывает 95% реальных кейсов.

Пользователь должен **явно** переключить на Temporary если осознанно нужно.

### 2.3 Что определяет выбор режима

Простое правило:

> Если эти данные могут пригодиться в другом диалоге или другом flow — **Contact profile**.
> Если данные нужны только сейчас и потом не имеют смысла — **Temporary**.

Примеры Contact profile:
- Имя пользователя (нужно во всех будущих диалогах)
- Выбранный тариф (для напоминаний, апсейлов)
- Ответы на опрос (для аналитики)
- Адрес доставки

Примеры Temporary:
- Код подтверждения SMS (используется один раз для проверки)
- Параметр запуска опроса (department, period — это не свойство контакта)
- Промежуточные ответы при многошаговом подтверждении

---

## 3. UI компонентов

### 3.1 Input нода — спросить пользователя

```
┌──────────────────────────────────┐
│ ❓ Ask user                       │
│                                  │
│ Question: "Введите код"          │
│                                  │
│ Save as:                         │
│ ┌────────────────────────────┐   │
│ │ Name: [code              ] │   │
│ │ Type: [Number     ▾      ] │   │
│ │                            │   │
│ │ Save to:                   │   │
│ │ ◉ 💾 Contact profile        │   │
│ │ ○ ⏱ Temporary               │   │
│ │                            │   │
│ │ Group: [— ▾]               │   │
│ └────────────────────────────┘   │
└──────────────────────────────────┘
```

**Поля:**

| Поле | Описание |
|------|----------|
| Question | Текст вопроса для пользователя |
| Name | Имя переменной (alphanumeric + underscore) |
| Type | Тип ожидаемого ответа: Text, Number, Email, Phone, Contact, Select, Confirm, File, Photo, Location, Date |
| Save to | Радио-выбор: Contact profile (дефолт) или Temporary |
| Group | Опциональная группа (см. раздел 4) |

### 3.2 Assign нода — записать значение

```
┌──────────────────────────────────┐
│ 📝 Set value                     │
│                                  │
│ Operations:                      │
│ ┌────────────────────────────┐   │
│ │ Name: [full_name         ] │   │
│ │ Type: [Text       ▾      ] │   │
│ │                            │   │
│ │ Value:                     │   │
│ │ [{{first_name}} {{last...}}│   │
│ │                            │   │
│ │ Save to:                   │   │
│ │ ◉ 💾 Contact profile        │   │
│ │ ○ ⏱ Temporary               │   │
│ │                            │   │
│ │ Group: [— ▾]               │   │
│ └────────────────────────────┘   │
│ [+ Add operation]                │
└──────────────────────────────────┘
```

Та же структура переменной что в Input — Name, Type, Save to, Group. Плюс Value (выражение для вычисления).

Можно добавлять несколько операций в одну ноду (`[+ Add operation]`).

### 3.3 Visual cues в графе

На карточках нод в графе flow видны иконки источника данных:

```
┌───────────────┐    ┌───────────────┐
│ ❓ Ask         │    │ ❓ Ask         │
│ "Имя?"        │    │ "Код?"        │
│ → name 💾      │    │ → code ⏱      │
└───────────────┘    └───────────────┘

┌───────────────┐    ┌───────────────┐
│ 📝 Set        │    │ 🌐 API Call   │
│ full_name 💾  │    │ ← response ⚡ │
└───────────────┘    └───────────────┘
```

С первого взгляда видно:
- 💾 — сохранится в профиль
- ⏱ — временно, исчезнет
- ⚡ — runtime результат API
- 🧠 — результат RAG

---

## 4. Группы

### 4.1 Зачем нужны группы

Связанные поля логически объединяются в группу:

```
Профиль Иван Петров:
  name: Иван
  phone: +7...
  
  ▼ Опрос Q1 2026:
    overall_score: 8
    recommendation: yes
    comment: Отлично
  
  ▼ Адрес:
    city: Москва
    street: Ленина 1
```

Польза:
1. **Структура в карточке контакта** — поля не свалены в кучу, сгруппированы
2. **Аналитика** — выгружать ответы опроса можно по имени группы (один SQL для всей группы)
3. **Гигиена данных** — связанные поля одной операции (опроса, формы) живут вместе

### 4.2 Группа в UI Input/Assign

```
Save as:
  Name: [overall_score    ]
  Type: [Number  ▾        ]
  Save to: ◉ 💾 Contact profile
  Group: [survey_q1 ▾]
```

Поле Group — опциональное. Без группы поле сохраняется в корень.

### 4.3 Group dropdown поведение

```
Group dropdown:
  [▾]
  ─────────────
  (No group)               ← дефолт, поле в корне
  ─────────────
  survey_q1                ← существующие группы
  address
  profile
  ─────────────
  + Create new group...    ← создать новую
```

При выборе `(No group)` — поле сохраняется в корень `attributes`.

При выборе существующей группы — поле сохраняется внутри неё.

При `+ Create new group...` — диалог ввода имени новой группы.

### 4.4 Глубина группировки

V1 ограничение: **1 уровень группы**.

```
contact.name                    ✓ корень
contact.survey_q1.overall_score ✓ 1 уровень группы
contact.survey_q1.dept.score    ✗ 2 уровня — запрещено
```

Если ввести имя поля содержащее точку (например `dept.score`) — UI показывает ошибку «Глубина превышена».

### 4.5 Naming групп — convention

Рекомендации (не enforced):
- `survey_q1_2026` — период + версия в имени
- `onboarding_v2` — назначение + версия
- `profile`, `address`, `preferences` — простые группы

Versioning в имени поможет при изменениях схемы опроса со временем.

### 4.6 Зарезервированные группы

Группа `meta` зарезервирована — там данные платформы (username из Telegram, и т.п.). UI не позволяет создать группу с этим именем.

---

## 5. Условия (Branch) — выбор источника

### 5.1 Чтение пользовательских переменных

Если в условии используется переменная сохранённая через Input/Assign — простой dropdown по имени:

```
IF  [code ▾]  equals  [1234]
THEN → branch "match"
ELSE → branch "no_match"
```

Без выбора источника. Имя переменной уникально определяет место хранения.

### 5.2 Чтение не-пользовательских данных

Когда условие проверяет что-то **другое** (не сохранённую переменную) — появляется source picker:

```
IF  [Source: ▾                       ]
     ─────────────────────────
     📇 Contact field
     🧠 RAG result
     ⚡ API response
     🏢 HR data (module)
     💬 Last user message
     ─────────────────────────

  [field ▾]  equals  [...]
```

Источники:

| Иконка | Источник | Что доступно |
|--------|----------|--------------|
| 📇 | Contact field | Поля профиля контакта |
| 🧠 | RAG result | Результат последнего поиска в базе знаний (found, confidence, answer) |
| ⚡ | API response | Результат последнего API вызова (call ноды) |
| 🏢 | HR data | Данные HR модуля (department, position) — если модуль активирован |
| 💬 | Last user message | Текст последнего сообщения пользователя |

Source picker появляется только когда нужен — для не-Contact источников.

---

## 6. Picker переменных — вставка в текст

### 6.1 Где используется

В полях где нужно подставить переменную в текст:
- Send Message text (текст сообщения)
- Validation error messages
- Call URL и parameters
- Assign value expression

### 6.2 UI picker

При нажатии иконки `{...}` рядом с полем text — появляется список доступных переменных:

```
┌─────────────────────────────────────┐
│ {{contact.name}}              copy  │
│ {{contact.username}}          copy  │
│ {{contact.phone}}             copy  │
│ {{contact.channel}}           copy  │
│ ─── survey_q1 ─────────             │
│ {{contact.survey_q1.score}}   copy  │
│ {{contact.survey_q1.comment}} copy  │
│ ─── address ───────────             │
│ {{contact.address.city}}      copy  │
└─────────────────────────────────────┘
```

Группы автоматически собираются по common prefix и показываются с разделителями.

### 6.3 Иконки источников

В polном picker'e (когда доступны не только Contact поля):

```
┌─────────────────────────────────────┐
│ 💾 contact.name                     │
│ 💾 contact.phone                    │
│ 💾 contact.survey_q1.score          │
│ ⏱ flow.confirmation_code           │
│ 🧠 rag.answer                       │
│ ⚡ call.response.status              │
│ 🏢 module.hr.department              │
│ 💬 system.last_user_message         │
└─────────────────────────────────────┘
```

Иконка показывает категорию источника. Клик copy — вставка в редактируемое поле.

---

## 7. Контактная карточка — отображение групп

### 7.1 Структура

В Filament admin → Contacts → конкретный контакт:

```
📇 Иван Петров
  Channel:    Telegram
  Channel ID: 123456789
  Username:   @ivanov (auto from Telegram)
  
  ─── Profile ─────
  name:     Иван
  phone:    +7 999 123 4567
  email:    ivan@example.com
  
  ─── ▼ survey_q1_2026 (3 fields) ───
  overall_score:    8
  recommendation:   yes
  comment:          Отлично
  
  ─── ▼ address (2 fields) ───
  city:             Москва
  street:           Ленина 1
```

Корневые поля показаны сверху. Группы — секциями ниже, свёрнутые/развёрнутые по клику.

### 7.2 Что показывается

- **Только Contact profile поля** (💾)
- Платформенные данные (meta — username, и т.п.) — отдельной секцией с пометкой "from platform"
- Temporary поля (⏱) — НЕ показываются (они в session, не в Contact)

Это даёт ясное правило: «если хочешь видеть в профиле — сохраняй через Contact profile».

---

## 8. Логирование истории

### 8.1 Флаг на уровне flow

В настройках flow definition:

```
Flow settings:
  Name: [Сбор профиля]
  Description: [...]
  
  ☑ Логировать историю изменений
     Сохраняет все ответы пользователя и изменения данных для последующего анализа.
```

Флаг `logging_enabled`:
- ✓ ON — все изменения state логируются (input ответы, выборы кнопок, assign операции, API responses)
- ✗ OFF (дефолт) — только базовые execution logs

### 8.2 Что попадает в логи

Когда флаг включён, для каждой сессии записываются:

- Каждый ответ пользователя (input)
- Каждый выбор кнопки (если для send_message включено хранение)
- Каждое присвоение значения (assign)
- Результаты API вызовов (call result_mapping)
- Результаты RAG запросов
- Subflow start/return events с навигацией к child sessions
- Ошибки и failures нод

### 8.3 Use cases

- **Аналитика опросов** — выгрузить все ответы по группе `survey_q1_2026` за период
- **Audit** — увидеть полную историю изменений профиля контакта
- **Debugging** — понять почему flow пошёл не туда
- **Compliance** — доказать что согласие было дано в конкретный момент

### 8.4 Subflow — независимое логирование

Каждый flow_definition имеет **свой** флаг logging_enabled. Parent и child — независимы:

```
Parent (Onboarding) — logging ON
  ├── собственные ноды логируются
  └── вызывает subflow CollectPersonalData
        ↓
        Child (CollectPersonalData) — logging OFF
              └── внутренние ноды НЕ логируются
```

Parent история содержит navigation events:
- `subflow_started` (с child_session_id)
- `subflow_returned` (с outcome: success/cancelled/failed)

Внутренности child — пустая история (его флаг выключен).

Если включить logging для child — child history содержит детали внутренних нод. Связь parent↔child через child_session_id.

---

## 9. Workflow примеры

### 9.1 Простой опрос с группой

Создаём flow «Опрос удовлетворённости Q1 2026»:

1. Ask user "Оцените от 1 до 10"
   - Name: `overall_score`
   - Type: Number
   - Save to: 💾 Contact profile
   - Group: `survey_q1_2026`

2. Ask user "Порекомендовали бы коллегам?"
   - Name: `recommendation`
   - Type: Confirm (yes/no)
   - Save to: 💾 Contact profile
   - Group: `survey_q1_2026`

3. Ask user "Комментарий"
   - Name: `comment`
   - Type: Text
   - Save to: 💾 Contact profile
   - Group: `survey_q1_2026`

4. Send message "Спасибо за обратную связь!"

5. End

Включить флаг "Логировать историю изменений" в настройках flow.

**Результат в Contact:**
```
contact.attributes = {
  "survey_q1_2026": {
    "overall_score": 8,
    "recommendation": true,
    "comment": "Отлично"
  }
}
```

**Аналитика:** SQL запрос по `attributes->'survey_q1_2026'` агрегирует ответы всех контактов прошедших опрос.

### 9.2 Подтверждение через временный код

Flow «Подтверждение действия через SMS»:

1. Send message "Отправляем код на ваш телефон"

2. Call API → отправка SMS с кодом 1234

3. Ask user "Введите код из SMS"
   - Name: `entered_code`
   - Type: Number
   - Save to: ⏱ **Temporary** (одноразовый, не нужно в профиле)

4. Branch: `entered_code` equals `1234`?
   - true → Send "Подтверждено!" → End
   - false → Send "Неверный код" → loop назад к шагу 3

Код существует только в session. После завершения flow исчезает.

### 9.3 Параметризованный опрос (workaround V1 без параметров)

Flow «Запустить опрос для отдела» (parent):

1. Ask user "Какой отдел опрашиваем?"
   - Name: `_target_department`
   - Type: Text
   - Save to: ⏱ **Temporary** (это параметр запуска, не свойство менеджера)

2. Ask user "Какой период?"
   - Name: `_target_period`
   - Type: Text
   - Save to: ⏱ Temporary

3. Subflow: вызвать flow «Опросить сотрудников» (child).

В V1 без параметров child читает данные из `contact.attributes._tmp_*` если задано в parent. С V1.1 параметры будут переданы явно.

---

## 10. Что в V1 / Что позже

### 10.1 В V1

- Два режима хранения: 💾 Contact profile / ⏱ Temporary
- Имя переменной + тип + Save to + Group в одной форме
- Group dropdown с опцией создания новой
- Глубина группировки 1 уровень
- Source picker в Branch для не-Contact источников
- Variable picker для вставки в text fields
- Иконки в карточках нод 💾/⏱/⚡/🧠
- Логирование истории — флаг per flow
- Группы видны в карточке Contact как секции

### 10.2 Не в V1

- Per-node logging override (только per-flow в V1)
- UI report builder поверх групп (пока только raw SQL)
- Глубина группировки > 1 уровень
- Tooltip с примерами при наведении на режимы хранения
- Bulk rename переменных в редакторе (refactoring tool)
- Schema validation для групп (формальное описание полей и типов)
- Поиск переменных в variable picker (когда их много)

---

## 11. Технические заметки (для разработчиков)

Этот раздел — для команды реализации, **не** для пользователей.

### 11.1 Compiler UI → JSON snapshot

UI операция «Save as Contact profile, Group=form, Name=input1» компилируется в:

```json
{
  "type": "input",
  "config": {
    "variable": {
      "name": "input1",
      "storage": "contact",
      "group": "form"
    }
  }
}
```

Engine при выполнении резолвит target path:
- storage=contact, group=form, name=input1 → запись в `contact.attributes.form.input1`
- storage=session, group=null, name=code → запись в `flow_sessions.state.flow.code`

### 11.2 Resolver чтения

Variable picker генерирует expressions:
- `{{contact.name}}` — Contact resolver идёт по path
- `{{contact.survey_q1_2026.score}}` — nested JSONB path
- `{{flow.code}}` — session state

Все это работает через ScopedStateReader (см. ADR State Writer Semantics).

### 11.3 Group discovery

Список групп для dropdown собирается из:
1. Существующие группы в этом flow definition (other Input/Assign nodes)
2. Существующие группы tenant-wide (из Redis cache, обновляется при publish flow)

### 11.4 Validation

При save flow definition (draft):
- Naming валидация: alphanumeric + underscore
- Reserved keys check (id, channel_id, meta, ...)
- Глубина группы ≤ 1
- Soft warnings при нарушениях (не блокируют save)

При publish:
- Все проверки → blocking errors
- Cross-references (subflow callees) проверяются
- См. ADR Subflow Composition

---

## 12. Открытые вопросы

Список открытых UX вопросов которые могут потребовать решения по мере реализации:

1. **Tooltip с примерами** — нужен ли при наведении на «Contact profile» / «Temporary» с примерами использования? (V1.x вероятно)
2. **Confirmation при выборе Temporary** — диалог «Точно временно? Эти данные исчезнут после завершения flow.»? (UX research)
3. **Autocomplete в Name field** — предлагать ли существующие имена переменных при вводе?
4. **Drag-and-drop reordering групп** в Contact карточке — нужно ли?
5. **Visual differ** при изменении Save to — показать что изменится в карточке Contact?

Эти вопросы НЕ блокируют V1 release.

---

## 13. Связанные документы

- `specs/flow-engine/_snapshot-v1.0.md` — спецификация нод, JSON snapshot, validation rules
- `10-state-writer-semantics.md` — backend модель хранения, history logging
- `08-expression-language.md` — expression syntax для variable picker
- `09-message-routing-concurrency.md` — поведение runtime
- `11-subflow-composition.md` — subflow lifecycle и history navigation

---

**Документ финальный для V1. Готов к передаче дизайнерам и реализации Flow Constructor UI.**

---

## Связано с

- [[00-overview]] — overview storage
- [[page-structure]] — структура builder
