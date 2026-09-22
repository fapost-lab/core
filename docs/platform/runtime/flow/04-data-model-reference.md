# 04. Data Model Reference (ключевые таблицы и поля)

Ниже не полный ERD всей платформы, а только то, что критично для пути:

`webhook -> queue -> session resolve -> flow execution -> outbound`

## `channels`

Источник маршрутизации webhook и исходящей доставки через assistant.

| Поле                  | Тип                | Что значит                                                |
|-----------------------|--------------------|-----------------------------------------------------------|
| `id`                  | uuid               | Идентификатор канала                                      |
| `tenant_id`           | uuid               | Tenant-владелец                                           |
| `assistant_id`        | uuid FK            | К какому ассистенту привязан канал                        |
| `type`                | string             | Тип провайдера (`telegram`, `whatsapp`, ...)              |
| `token`               | text               | Токен транспорта (используется outbound sender)           |
| `secret_token`        | text               | Токен проверки подписи inbound webhook                    |
| `webhook_public_hash` | string(64), unique | Публичная часть webhook URL (`/webhook/{channel}/{hash}`) |
| `is_active`           | bool               | Можно ли использовать канал для входящих/исходящих        |

## `contacts`

Бизнес-идентичность пользователя внутри tenant/platform.

| Поле          | Тип    | Что значит                                           |
|---------------|--------|------------------------------------------------------|
| `id`          | uuid   | Contact ID                                           |
| `tenant_id`   | uuid   | Tenant                                               |
| `platform`    | string | Платформа контакта                                   |
| `external_id` | string | ID пользователя на стороне провайдера (chat/user id) |
| `meta`        | jsonb  | Технический профиль (name, username, и т.д.)         |
| `attributes`  | jsonb  | Бизнес-данные, собранные flow-нодами                 |

Ключевой индекс: `unique(tenant_id, platform, external_id)`.

## `channel_contacts`

Связь "через какой канал доставлять этому контакту".

| Поле                  | Тип       | Что значит                                     |
|-----------------------|-----------|------------------------------------------------|
| `contact_id`          | uuid FK   | Контакт                                        |
| `channel_id`          | uuid FK   | Канал                                          |
| `last_interaction_at` | timestamp | Когда последний раз был обмен через эту связку |

Используется в `FlowMessageSender` и `FallbackMessageService` для выбора активного канала.

## `flow_sessions`

Главная runtime-сущность движка.

| Поле                 | Тип                | Что значит                                          |
|----------------------|--------------------|-----------------------------------------------------|
| `id`                 | uuid               | Session ID                                          |
| `tenant_id`          | uuid               | Tenant контекст                                     |
| `assistant_id`       | uuid FK            | Ассистент, от имени которого идёт диалог            |
| `contact_id`         | uuid FK            | Контакт сессии                                      |
| `flow_definition_id` | uuid FK            | Конкретный snapshot definition (версия фиксируется) |
| `flow_version`       | uint               | Версия flow definition                              |
| `current_node_id`    | string nullable    | Текущая нода исполнения                             |
| `state`              | jsonb              | Runtime state (`system.*`, `flow.*`, ...)           |
| `status`             | string             | Состояние сессии                                    |
| `version`            | bigint             | Версия optimistic lock                              |
| `expires_at`         | timestamp nullable | Время жизни (если применяется)                      |

### Разрешённые `status`

Полный список — enum `App\Domains\Flow\Enums\FlowSessionStatus`, зеркалируется CHECK-constraint'ом
на колонке `flow_sessions.status` (см. миграцию `2026_05_06_000003_extend_flow_sessions_status_constraint`):

- `pending`
- `active`
- `waiting_input`
- `paused`
- `paused_subflow` (добавлен отдельной миграцией, subflow lifecycle)
- `completed`
- `ended` (добавлен отдельной миграцией, subflow lifecycle)
- `failed`
- `cancelled` (добавлен отдельной миграцией)
- `expired` (добавлен отдельной миграцией, subflow lifecycle)
- `terminated_by_user` (добавлен отдельной миграцией, subflow lifecycle)

### Где используются статусы

| Статус                | Практический смысл                                                                                                         |
|------------------------|-----------------------------------------------------------------------------------------------------------------------------|
| `active`               | Движок выполняет граф или готов продолжать                                                                                  |
| `waiting_input`        | Ждём ответ пользователя                                                                                                     |
| `paused`               | Нода вернула `Delayed` (например, `delay`-нода) — сессия ждёт таймер                                                        |
| `paused_subflow`       | Родительская сессия приостановлена, пока выполняется дочерний subflow (`SubflowStarterService`)                            |
| `completed`            | Граф исчерпан без явной `end`-ноды (`NodeExecutionStatus::Finished`, включая resume родителя после subflow без узла-продолжения) |
| `ended`                | Сессия завершена явной `end`-нодой; финальный исход хранится в `end_status` (`success`/`cancelled`/`failed`)               |
| `failed`               | Ошибка выполнения ноды/движка (`NodeExecutionStatus::Failed`)                                                               |
| `cancelled`            | Есть в enum и в CHECK-constraint'е, задокументирован в `FlowSessionRepositoryInterface::cancel()` как статус reset-команд, но на момент написания ни один вызывающий код в `app/` его не использует — фактические reset-команды (`GlobalCommandExecutor`) переводят сессию в `terminated_by_user` |
| `expired`              | `SubflowTimeoutSweeper` пометил осиротевшего родителя (в `paused_subflow`, `expires_at` истёк, живого child нет)            |
| `terminated_by_user`   | Принудительно завершена глобальной командой (`/reset`-подобные) через `GlobalCommandExecutor::markTerminated`              |

## `flow_triggers`

Правила старта flow, когда active session отсутствует.

| Поле                         | Тип           | Что значит                              |
|------------------------------|---------------|-----------------------------------------|
| `tenant_id`                  | uuid          | Tenant                                  |
| `assistant_id`               | uuid nullable | Если null - глобальный trigger          |
| `flow_id`                    | uuid          | Логический ID flow                      |
| `type`                       | string        | `message`, `schedule`, `webhook`, `api` |
| `is_active`                  | bool          | Включён/выключен                        |
| `priority`                   | uint          | Приоритет выбора                        |
| `config`                     | jsonb         | Условия trigger (keywords, cron, ...)   |
| `last_run_at`, `next_run_at` | timestamp     | Для планировщика и аналитики            |

## `flow_logs` и `analytics_events`

### `flow_logs`

Пошаговый технический trace исполнения нод:

- `session_id`, `node_id`, `node_type`, `node_version`
- `status` (`executed/failed/conflict/terminal`)
- `state_changes`, `resolved`, `error`

### `analytics_events`

Бизнес-события верхнего уровня:

- `flow_started`
- `flow_completed`
- `flow_failed`

Если нужен "путь по нодам" - смотри `flow_logs`.
Если нужен "бизнес счётчик" - смотри `analytics_events`.
