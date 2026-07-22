# 09 · Flow `logging_enabled` flag + per-session history

**Зависит от:** —
**Блокирует:** —
**Слой:** backend (PHP) + Filament admin

## Цель

Соответствует п.8 спеки. Добавить per-flow флаг `logging_enabled` (boolean, default false). Когда включён — runtime пишет детальные записи в `flow_session_history` для каждой сессии этого flow:
- Каждый ответ пользователя (input)
- Каждое присвоение (assign operations)
- Каждый выбор кнопки в send_message
- Результаты API call (result_mapping)
- Результаты RAG query
- Subflow start/return events
- Ошибки и failures

Когда выключен — пишутся только базовые execution logs (как сейчас в `flow_logs`).

## Текущее состояние

`flow_session_history` таблица уже существует (см. миграцию `2026_05_06_000005_create_flow_session_history_table.php`). `DefaultHistoryWriter` / `NoOpHistoryWriter` существуют (см. FlowServiceProvider). `HistoryWriterFactory` где-то выбирает writer.

Проверить что выбор writer реально завязан на per-flow флаг (а не глобальный config) — судя по именам, скорее всего да, но в spec terms может быть либо `flow_definitions.logging_enabled` колонка отсутствует, либо UI флага нет.

## Что меняем

### 1. Schema (если ещё нет)

`flow_definitions.logging_enabled` boolean default false. Если колонки нет — миграция:

```php
Schema::table('flow_definitions', function (Blueprint $table): void {
    $table->boolean('logging_enabled')->default(false)->after('is_active');
});
```

То же для `flow_drafts` (UI authors хранят флаг в драфте).

### 2. HistoryWriterFactory — wiring

Factory выбирает writer на основании `$flowDefinition->logging_enabled`:
- true → `DefaultHistoryWriter`
- false → `NoOpHistoryWriter`

### 3. Filament UI — flow settings

В Filament Assistant → Flows → Edit добавить переключатель:

```
Flow settings:
  Name: [...]
  Description: [...]
  Group: [...]

  ☑ Log change history
     Saves all user answers and data changes for later analysis.
```

Toggle в `FlowFormSchema` или вынести в отдельный «Settings» tab если форма растёт.

### 4. Vue builder — флаг в Settings

Если у flow в builder'е есть собственная Settings панель — добавить toggle. Если нет (как сейчас, builder — graph editor) — флаг редактируется только из Filament. Это приемлемо в V1.

### 5. История в admin

Это уже частично сделано в FlowSession Inspector (см. CLAUDE.local.md «FlowSession Inspector + FlowLog Viewer» — completed). Добавить:
- Колонку «Logged» в FlowSession list, если у flow_definition флаг включён
- В FlowSession detail view — секция «History» с таймлайном из `flow_session_history`
  - Каждая запись: timestamp, node, тип события (`input_received`, `assigned`, `subflow_started`, …), payload (краткий)
  - Drill-down к child sessions через `child_session_id` (для subflow events)

### 6. Subflow — независимое логирование

Каждый flow_definition имеет свой `logging_enabled`. Parent с logging=true пишет навигационные события (`subflow_started`, `subflow_returned`); внутренности child пишутся только если у child тоже включено (см. п.8.4 спеки).

## Файлы

- Migration: `database/migrations/tenant/{date}_add_logging_enabled_to_flow_definitions.php` (если колонки нет)
- `app/Domains/Flow/Models/FlowDefinition.php` — fillable + cast
- `app/Domains/Flow/Models/FlowDraft.php` — то же
- `app/Domains/Flow/History/HistoryWriterFactory.php` — проверить логику выбора writer
- `app/Filament/Assistant/Resources/Flows/Schemas/FlowFormSchema.php` — добавить toggle
- `app/Filament/Assistant/Resources/FlowSessions/Schemas/FlowSessionInfolistSchema.php` — добавить History секцию
- `lang/{en,ru,uk}/assistant.php` — переводы

## Acceptance

- Toggle в Flow form работает: state read/write, default false.
- При publish — `flow_definitions.logging_enabled` отражает значение draft.
- Flow с logging=true → run сессия → `flow_session_history` содержит записи по всем нодам.
- Flow с logging=false → `flow_session_history` пустая (но в `flow_logs` базовые execution logs есть).
- FlowSession detail в Filament показывает History tab только если у этой сессии есть записи.
- Subflow с logging=false внутри parent с logging=true → parent history содержит только navigation events; child history пустая.

## Out of scope

- Per-node logging override (V1.x backlog, см. п.10.2 спеки)
- UI report builder поверх group attributes (V1.x)

---

## Связано с

- [[11-logging-retention]] — logging и retention архитектура
- [[00-overview]] — overview storage
- Retention/partitioning policy для `flow_session_history` (отдельная ops-задача)
