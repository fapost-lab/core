# FaPost Core — Проект

> Единый живой документ: продукт · архитектура · слои · дорожная карта.
> Детальные спеки и диаграммы — по ссылкам. Навигация — [[INDEX]].

---

## О проекте

**FaPost** (Flow Automation Post) — платформа для создания диалоговых ассистентов в мессенджерах: Telegram, Viber,
WhatsApp, Facebook Messenger, Slack, Microsoft Teams и далее по спросу. Целевая аудитория: средний и крупный бизнес,
которому нужна автоматизация коммуникаций без найма разработчиков.

Канал — сменная деталь, а не встроенное допущение: flow, контакты, транскрипты и рассылки не знают про конкретный
мессенджер. Адаптер реализует `ChannelAdapterInterface` (нормализация webhook + отправка), отправитель —
`ChannelInterface` в foundation; `IncomingMessage::$platform` и `OutboundMessage::$channelType` заложены в DTO.
Новый мессенджер — это новый адаптер, а не переработка ядра.

> **Что реализовано сегодня:** только Telegram Bot API. `WhatsAppAdapter` существует как заглушка — все методы
> бросают `LogicException`, `PlatformEnum` содержит `whatsapp` и `email`. Остальные каналы — заявленное направление
> продукта, не обещание к сроку: отдельного milestone под них в [[ROADMAP]] нет, адаптеры делаются под спрос.

**Два режима поставки:**
- **SaaS** — облако, подписка, многоарендная модель, биллинг, план-управление
- **Self-hosted** — on-premise, один tenant, клиент управляет сам

**Первый нишевый модуль:** FaPost HR — онбординг, 360° оценки, опросы.

**Архитектура репозиториев:** FaPost Core (этот репо) · FaPost HR / FaPost {Niche} (отдельные репо, Solutions) · FaPost SaaS (отдельный репо, оболочка).

---

## Стек

| Слой | Технология |
|------|-----------|
| Backend | PHP 8.4 + Laravel 12 |
| Admin UI | Filament 5 |
| Builder (фронт) | Inertia + Vue 3 |
| Очереди | Laravel Horizon 5 |
| Webhook ingress | PHP-FPM; опционально Go-гейтвей (`gateway/`) перед ним |
| База данных | PostgreSQL (schema per tenant) |
| Кэш / очереди / locks | Redis |
| Мессенджеры | Telegram Bot API (реализован); Viber / WhatsApp / Messenger / Slack / Teams — через `ChannelInterface` |

---

## Архитектурные слои

```
┌─────────────────────────────────────────┐
│           SaaS Shell (отдельный репо)    │  tenants, billing, plans, flags
├─────────────────────────────────────────┤
│         FaPost Core (этот репо)          │  assistants, flow engine, messaging
│  ┌────────────┐  ┌──────────────────┐   │
│  │  Domains   │  │    Features      │   │  RAG, AccessControl, Broadcasting
│  └────────────┘  └──────────────────┘   │
├─────────────────────────────────────────┤
│         Solutions / Plugins              │  FaPost HR, внешние расширения
│  ┌────────────────────────────────────┐ │
│  │  fapost/foundation (contracts SDK) │ │
│  │  fapost/support (shared primitives)│ │
│  └────────────────────────────────────┘ │
└─────────────────────────────────────────┘
```

**Три концепта расширения:**

| Понятие | Где живёт | Активация | Vue компоненты |
|---------|-----------|-----------|----------------|
| Feature | `app/Features/` | `tenant_activations` | ✓ |
| Solution | composer-пакет | `tenant_activations` | ✓ (с перебилдом) |
| Plugin | composer-пакет | `PluginRegistry` | ✗ (runtime install) |

---

## Домены

| Домен | Путь | Ответственность |
|-------|------|----------------|
| **Tenancy** | `app/Domains/Tenancy/` | TenantContext, схема БД, provisioning |
| **Flow** | `app/Domains/Flow/` | Flow engine, handlers, session, state, builder |
| **Messaging** | `app/Domains/Messaging/` | Webhook routing, senders, Telegram adapter |
| **Assistant** | `app/Domains/Assistant/` | Assistant + Channel агрегаты, Redis registry |
| **Contact** | `app/Domains/Contact/` | Контакты, теги, группы, сегменты |
| **Media** | `app/Domains/Media/` | Хранение медиа, деdup SHA-256, channel refs |
| **Conversation** | `app/Domains/Conversation/` | Транскрипт диалогов, инбокс оператора, takeover |
| **Shared** | `app/Domains/Shared/` | BaseModel, HasUlidPrimaryKey, общие трейты |

---

## Ключевые архитектурные законы

> Полные ADR — [[architecture/adr/README]] (через [[INDEX]])

**1. Tenant-aware execution всегда.** Tenant — базовая координата runtime. Нет fallback на дефолтный tenant. Hard fail если контекст не установлен.

**2. Migration Isolation.** Миграция — чистая DDL. Запрещены: `app()`, `config()`, `TenantContext::get()`, seed-данные зависящие от runtime.

**3. Handler Version Contract.** Handler резолвится по `(type, version)` из in-memory registry. Breaking change → version++, старый handler остаётся. Сессия выполняется по своему snapshot до конца.

**4. Ingress boundary (ADR-01, отменён).** Ingress stateless: без БД, только Redis и dispatch. Это позволяет вынести его за пределы PHP — роль быстрого ingress выполняет опциональный Go-гейтвей (`gateway/`), а приложение обслуживает те же запросы на PHP-FPM. [[diagrams/01-webhook-pipeline]]

**5. ID Strategy (ADR-03).** ULID в PostgreSQL `uuid` колонке. Трейт `HasUlidPrimaryKey`. FK через `foreignUuid().constrained().cascadeOnDelete()`.

**6. Dependency Direction.** `fapost/foundation` и `fapost/support` не зависят от Core. Импорт `App\*` в пакетах = баг.

**7. Runtime Surface Governance.** Только `CoreRegistrar` как точка входа для routes, schedule, migrations расширений.

---

## Flow Engine

> Спека: [[specs/flow-engine/README]] · Диаграммы: [[diagrams/02-flow-engine-loop]], [[diagrams/01-webhook-pipeline]]

**Принципы:**
- Flow = граф нод в JSON (`flow_definitions.nodes`). Engine детерминирован.
- Handler резолвится по `(type, version)` из in-memory `NodeHandlerRegistry` — без запросов в БД.
- Handler возвращает `sourceHandle`, не `nextNodeId` — graph-unaware.
- State строго namespaced: `system.*` (engine), `flow.*` (input/assign), `rag.*` (rag_query), `module.*` (read-only через DataAccessor).

**Concurrency — три уровня:**
1. Idempotency key: `Redis SET NX "processed:{update_id}" EX 86400`
2. Distributed lock: `Redis lock "session_lock:{tenant}:{contact}:{assistant}" TTL=30s`
3. Optimistic lock: `flow_sessions.version` — `UPDATE WHERE version = N`

**Таксономия нод:** актуальные статусы реализации см. в [[TASKS]].

| Нода | Тип | P |
|------|-----|---|
| `send_message` | Core | P0 |
| `input` | Core | P0 |
| `condition` / `branch` | Core | P0 |
| `end` | Core | P0 |
| `delay` | Core | P1 |
| `assign` | Core | P1 |
| `call` | Core | P1 |
| `notify` | Core | P1 |
| `set_tag` | Core | P1 |
| `auth_request` | Core | P1 |
| `subflow` | Core | P2 |
| `loop` + `loop_end` | Core | P2 |
| `emit_event` | Core | P2 |
| `rag_query` | Feature: RAG | P2 |
| `comment` | Core (builder only) | P3 |

---

## Messaging Pipeline

> Диаграмма: [[diagrams/01-webhook-pipeline]]

```
Telegram → POST /webhook/{channel}/{hash}
         → Go-гейтвей (опционально) либо WebhookController (PHP-FPM)
         → Redis lookup: hash → {tenant_id, assistant_id, channel_id}
         → dispatch IncomingMessageJob (queue: flow.execution)
         → TenantContext::set + schema switch
         → MessageRouter: idempotency → command match → lock → session route
         → FlowOrchestrator → FlowEngine → NodeHandler
         → MessageSender → TelegramSender → Telegram API
```

**Очереди (изолированы):**

| Очередь | Назначение | Приоритет |
|---------|-----------|-----------|
| `messaging.transactional` | Ответы в диалоге | HIGH |
| `flow.execution` | Обработка входящих | HIGH |
| `messaging.broadcast` | Рассылки | LOW |
| `messaging.logging` | Запись диалогов | LOW |
| `messaging.system` | Staff уведомления | NORMAL |
| `scheduled.triggers` | Cron-запуски flow | LOW |

---

## Multi-tenancy

> Диаграмма: [[diagrams/03-tenant-context]]

**Две БД:**
- `landlord` — одна на платформу: `tenants`, `plans`, `subscriptions`
- `tenant_{slug}` — отдельная PostgreSQL schema per tenant

**Переключение:** `TenantContextInterface` (scoped binding) + `TenantSwitcher::runForTenant()` с restore в `finally` — критично для долгоживущих Horizon-воркеров.

**Self-hosted = один tenant**, создаётся при `platform:install`. Частный случай общей модели.

---

## Session Lifecycle

> Диаграмма: [[diagrams/04-session-state-machine]]

```
pending → active → waiting_input ⇄ active → paused_subflow ⇄ active → ended
                                                                         ├── success
                                                                         ├── cancelled
                                                                         └── failed
active / waiting_input → error (uncaught exception)
```

`flow_definition_id` фиксируется при старте и не меняется до конца сессии.

---

## Дорожная карта

> Полная версия с деталями: [[ROADMAP]]

| # | Milestone | Статус | Когда |
|---|-----------|--------|-------|
| M1 | Platform Core | ✅ | Закрыт (июнь 2026) |
| M2 | Runtime Hardening | ✅ | Закрыт |
| M3 | Conversation Logging | ✅ | Закрыт; reader-порт отложен до ClickHouse |
| M4 | Broadcasting & Segments | ✅ | Закрыт |
| M5 | RAG Feature | 🧊 | Бэклог, после M12 |
| M6 | emit_event + Event Chains | ✅ | Закрыт |
| M7 | Solutions Framework | 📋 | После M8 |
| M8 | Inbox / Live Chat | ✅ | Закрыт |
| M10 | SaaS Shell | 🔮 | Отдельный репо |
| M11 | Plugin Marketplace | 🔮 | После M7 |
| M12 | MCP Server | 📋 | После релизного трека M2 → M4 → M8 |

> Релизный трек **M2 → M4 → M8** закрыт — готово к выкладке и передаче в тестирование.
> M9 (Multi-channel / WhatsApp) удалён из планов.

**Фазы из исходного Notion-плана:**

| Фаза | Содержание | Статус |
|------|-----------|--------|
| Phase 0: Foundation | Scaffolding, Tenancy, BaseModel, ULID, Foundation SDK | ✅ |
| Phase 1: Core Platform | Staff, Assistant, Contact, Webhook, Flow Engine, Messaging | ✅ |
| Phase 2: Flow Depth | Concurrency, Versioning, Handler Versioning, Session State | ✅ |
| Phase 3: Builder & Media | Flow UI, Media Domain, i18n, Logging | ✅ |
| Phase 4: Modules | Module framework, RAG, HR Domain, Isolation, Broadcasts | 🔄 |
| Phase 5: SaaS | SaaS shell, billing, plans | 🔮 |

---

## Документация и статусы

`PROJECT.md` не ведёт чекбоксы и не дублирует задачи. Он нужен как быстрый проектный контекст.

| Где смотреть | Что там |
|--------------|---------|
| `.ai/scripts/jig status` | Текущий операционный фокус: активные задачи Jig |
| `.ai/specs/` | Планы открытых направлений (`.ai/scripts/jig spec list`) |
| [[TASKS]] | Что уже реализовано, по разделам |
| [[ROADMAP]] | История майлстоунов и production-ready критерии |
| [[INDEX]] | Полная карта vault |
| `../CLAUDE.md` | Правила для агентов, кодовые конвенции, архитектурные ограничения |

---

## Связано с

- [[INDEX]] — навигационный индекс всех документов
- [[ROADMAP]] — история инженерных майлстоунов
- [[TASKS]] — что уже реализовано, по разделам
- [[diagrams/README]] — все диаграммы
- [[specs/flow-engine/README]] — спека Flow Engine
- [[specs/messaging/conversation-logging]] — спека логирования диалогов
