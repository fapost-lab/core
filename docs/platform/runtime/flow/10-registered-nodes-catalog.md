# 10. Registered Nodes Catalog (текущее состояние)

Актуально по текущей регистрации в `FlowServiceProvider::boot()`.

На данный момент в `NodeHandlerRegistry` регистрируются 6 core-нод (все версии `v1`).

## Краткий список

| Type            | Version | Category      | Кратко что делает                                                                                                     |
|-----------------|--------:|---------------|-----------------------------------------------------------------------------------------------------------------------|
| `send_message`  |       1 | `Core`        | Отправляет сообщение (text/media/keyboard), поддерживает мультиязычность и шаблоны, умеет ждать нажатия inline-кнопки |
| `input`         |       1 | `Core`        | Ждёт входящее сообщение пользователя и сохраняет его в state по `save_to`                                             |
| `condition`     |       1 | `Logic`       | Проверяет значение из `flow/system/rag/module` и выбирает переход по rules                                            |
| `delay`         |       1 | `Logic`       | Ставит паузу через state-маркеры времени и переводит сессию в ожидание                                                |
| `set_attribute` |       1 | `Data`        | Пишет значение в `flow.*` или отдаёт effect на обновление contact (`language`/attribute)                              |
| `webhook`       |       1 | `Integration` | Делает исходящий HTTP POST с idempotency headers, роутит в `success/error`, может сохранить ответ в state             |

## По нодам подробнее

### `send_message` (`v1`)

- **Назначение:** отправка контента пользователю через `MessageSenderInterface`.
- **Поддержка контента:** `text`, `text_with_keyboard`, `image`, `document`, `video`, `voice`.
- **Особенности:**
    - защищена от дублей через `system.sent_messages`,
    - для inline-кнопок ждёт callback и возобновляет выполнение,
    - может проставлять timeout и переходить в `no_response`,
    - может удалить inline-клавиатуру после нажатия.
- **Типичные handles:** `default`, `no_response`, а также `button.id` для inline-кнопок.

### `input` (`v1`)

- **Назначение:** остановиться до входящего текста/ввода.
- **Логика:**
    - без `incoming` -> `Waiting`,
    - с `incoming` -> `Executed` + `sourceHandle=default`.
- **State:** пишет значение в путь из `config.save_to` (если задан).
- **Типичный handle:** `default`.

### `condition` (`v1`)

- **Назначение:** ветвление flow по правилам.
- **Поддерживаемые источники operand:**
    - `flow.*`, `system.*`, `rag.*`,
    - `module.*` через `DataAccessorRegistryInterface`.
- **Операторы:** `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `contains`, `in`, `empty`, `not_empty`.
- **Результат:** первый совпавший rule handle или `default`.

### `delay` (`v1`)

- **Назначение:** пауза между нодами.
- **Логика:**
    - первый проход ставит `resume_at/scheduled_at` в `system.delay.*`,
    - последующие проходы не планируют заново и остаются в `Waiting`.
- **Статус сессии:** через persister уходит в `waiting_input`/ожидание (по текущему статус-маппингу `Waiting`).

### `set_attribute` (`v1`)

- **Назначение:** записать вычисленное значение (поддерживает шаблоны).
- **Режимы:**
    - `target=flow` -> пишет в `flow.<key>`,
    - `target=contact` + `key=language` -> `effect: set_contact_language`,
    - `target=contact` + любой другой ключ -> `effect: set_contact_attribute`.
- **Типичный handle:** `default`.

### `webhook` (`v1`)

- **Назначение:** вызов внешнего HTTP endpoint из flow.
- **Что отправляет:** `session_id`, `contact_id` и выбранные `state` пути.
- **Идемпотентность:** заголовок `X-Idempotency-Key = {sessionId}:{nodeId}`.
- **Результат:**
    - успешный HTTP -> `Executed` + `success`/`error` по status code,
    - транспортная ошибка -> `Failed`.
- **Дополнительно:** может сохранить JSON-ответ по пути `save_response_to`.

## Важно про "актуальность"

Каталог отражает **фактически зарегистрированные** ноды в коде, а не только запланированные архитектурой.

Если добавляешь новую ноду:

1. Зарегистрируй её в `FlowServiceProvider::boot()`.
2. Обнови этот каталог.
