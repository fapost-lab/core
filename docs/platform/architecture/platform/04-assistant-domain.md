# 04 — Assistant Domain (Assistants + Channels)

> Зафиксировано: март 2026. Актуально начиная с Phase 1. Заменяет страницу «04 — Боты» и документ «Bot Domain — Terminology Reference».
> 

---

## Core Principle

`Assistant` — верхний бизнес-агрегат tenant.

`Channel` — transport endpoint, подчинённый `Assistant`.

`Flow` — логика обработки, принадлежит `Assistant`.

`Session` — runtime execution, запускается в контексте `Assistant`.

`Bot` как верхний термин больше не используется.

---

## Domain Structure

```
Assistant
 ├── Channels
 ├── Flows
 ├── Sessions
 ├── Context
 └── Logs
```

---

## Assistant

`Assistant` — самостоятельная управляемая единица внутри tenant.

Один tenant может иметь несколько `Assistant`. Примеры: Sales Assistant, Support Assistant, Warehouse Assistant.

**Содержит:**

| Поле | Описание |
| --- | --- |
| `id` | UUID |
| `tenant_id` | UUID |
| `name` | Имя ассистента |
| `is_active` | Активность |
| `default_flow_id` | Поток по умолчанию (nullable) |
| `fallback_message` | Сообщение при ошибке |
| `settings` | JSONB: AI/runtime настройки |

---

## Channel

`Channel` — подчинённая сущность `Assistant`. Отвечает **только** за транспорт и подключение. Не является самостоятельной бизнес-сущностью.

**Содержит:**

| Поле | Описание |
| --- | --- |
| `id` | UUID |
| `assistant_id` | FK → `assistants` |
| `tenant_id` | UUID |
| `type` | `telegram` \ |
| `token` | encrypted |
| `secret_token` | encrypted |
| `webhook_public_hash` | UNIQUE, opaque |
| `config` | JSONB: channel-specific |
| `is_active` | Активность |

---

## Runtime Rule

Любое входящее сообщение сначала попадает в `Channel`. Далее:

```
Incoming webhook
  → resolve channel (by public_hash из Redis)
  → resolve assistant (assistant_id из channel payload)
  → run assistant flow
```

Flow запускается не каналом, а **ассистентом**.

---

## Flow & Session Ownership

| Сущность | Владелец |
| --- | --- |
| `Flow` | `Assistant` |
| `Session` | `Assistant` |
| `Channel` | `Assistant` |

Один `Assistant` → несколько `Flow`.

Один `Channel` использует flow своего `Assistant`.

---

## Redis Webhook Registry

**Ключ:** `{REDIS_PREFIX}:webhook:{public_hash}`

**Значение:**

```json
{
  "tenant_id": "...",
  "assistant_id": "...",
  "channel_id": "...",
  "channel": "telegram",
  "secret_token": "plain_secret"
}
```

БД — источник истины. Redis — write-through cache, TTL не ставим.

---

## Distributed Lock Key

```
session_lock:{tenant_id}:{contact_id}:{assistant_id}
```

Изоляция по ассистенту, не по каналу.

---

## UI Architecture

### Главная Tenant Admin Panel

Разделы верхнего уровня:

- Пользователи
- **Ассистенты**
- Триггеры
- Рассылки
- Контакты
- Интеграции
- Настройки

### Assistant Management Panel

Отдельная административная область для конкретного ассистента. Вход — кнопка «Управлять».

Внутри: **Каналы** / Flows / Sessions / Context / Logs.

Позволяет изолировать управление и не перегружать главную панель.

---

## Triggers & Broadcasts

Остаются на уровне tenant. При работе с trigger/broadcast пользователь выбирает `Assistant`.

```
Trigger → Assistant
Broadcast → Assistant
```

---

## Access Control

User ↔ Assistants: many-to-many (`user_assistants` pivot).

Пользователь видит только назначенные ассистенты. Влияет на: список, assistant panel, выбор в триггерах, выбор в рассылках.

---

## Итоговые термины

| Слой | Термин |
| --- | --- |
| Бизнес-сущность | `Assistant` |
| Транспорт | `Channel` |
| Логика | `Flow` |
| Runtime состояние | `Session` |
| Таблица БД | `assistants`  • `channels` |
| UI (ассистент) | «Ассистенты» |
| UI (канал) | «Каналы» |

---

## Связано с

- [[06-flow-engine]] — flow engine работает через Assistant
- [[05-contacts]] — contacts привязаны к Assistant
- [[02-assistant-panel-console]] — ADR панели ассистента
- [[15-multilingual]] — default_language ассистента
- [[10-message-pipeline]] — message pipeline через ассистента
- [[diagrams/01-webhook-pipeline]] — диаграмма webhook pipeline
- [[03-staff-users]] — Staff пользователи и доступ