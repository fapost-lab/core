# Node Usage Statistics — Draft для доработки

**Документ:** Observability нод Flow Engine (static + runtime usage)
**Статус:** Черновик, требует доработки. Вынесен из addendum Loop-ноды (`flow-engine-v1/nodes/11-loop.md`), июнь 2026.
**Контекст:** независимая фича — не привязана к loop, полезна для всех node types.

---

## Связано с

- [[11-loop]] — loop нода и её usage statistics
- [[nodes/README]] — каталог нод
- [[06-flow-engine]] — flow engine архитектура

---

## 1. Цель

Ответить на два вопроса per tenant:

1. **Static:** какие node types и как часто используются в активных flow (определениях)?
2. **Runtime:** как часто и как успешно каждый node type реально исполняется?

---

## 2. Static analysis — готово к реализации

SQL view на `flow_definitions.nodes` (jsonb):

```sql
CREATE VIEW node_type_usage_static AS
SELECT
    fd.tenant_id,
    node->>'type' AS node_type,
    COUNT(*) AS usage_count
FROM flow_definitions fd,
     jsonb_array_elements(fd.nodes) AS node
WHERE fd.is_active = true
GROUP BY fd.tenant_id, node_type;
```

Дешёво, без записи в runtime. Спорных вопросов нет.

---

## 3. Runtime tracking — открытая проблема

### 3.1 Исходное предложение (отклонено)

Изначальный вариант — событие `node_executed` в `analytics_events`:

```json
{
  "event_type": "node_executed",
  "tenant_id": "...", "flow_id": "...", "session_id": "...",
  "node_id": "...", "node_type": "loop",
  "duration_ms": 145, "outcome": "success"
}
```

**Почему отклонено:** противоречит retention-модели CLAUDE.md. `analytics_events` — только
агрегированные бизнес-события (flow_started/completed/failed, …), хранятся **вечно**.
Per-node события — это raw-уровень: объём = каждая нода каждой сессии, на рассылках — миллионы
строк, которые нельзя будет дёшево чистить (DELETE по retention на тяжёлой таблице запрещён
дизайном; партиционирования у `analytics_events` нет).

### 3.2 Текущий interim-вариант

Агрегаты по существующей таблице `flow_logs` (partitioned monthly, retention 30 дней;
есть колонки `node_type`, `session_id`, `status`, `created_at`):

```sql
SELECT node_type, COUNT(*) AS executions
FROM flow_logs
WHERE created_at > now() - interval '30 days'
GROUP BY node_type;
```

**Ограничения interim-варианта (предмет доработки):**

- Окно ограничено retention (30 дней) — истории дольше нет.
- Flows с `logging_enabled = false` в выборку не попадают → статистика неполная и смещённая.
- `duration_ms` в `flow_logs` нет.
- `flow_logs` не пишет часть исполнений by design (delay executions при рассылках,
  idempotency-хиты) — см. CLAUDE.md § Логирование.

---

## 4. Направления доработки (решить до реализации)

1. **Агрегационная модель.** Кандидат: periodic rollup job `flow_logs` → компактная таблица
   `node_usage_daily (tenant_id, flow_id, node_type, date, executions, failures, avg_duration_ms)`.
   Переживает retention raw-логов, дёшево хранится вечно, согласуется с законом
   «analytics — агрегаты».
2. **Полнота vs `logging_enabled`.** Варианты: (а) принять неполноту, документировать;
   (б) лёгкие Redis-счётчики `INCR node_exec:{tenant}:{type}:{date}` независимо от flow_logs +
   nightly flush в rollup-таблицу; (в) всегда писать минимальную счётную запись. Вариант (б)
   выглядит дешевле всего для hot path.
3. **duration_ms.** Если нужен — добавлять колонку в `flow_logs` (raw-уровень), не в analytics.
   Оценить ценность: проблемные ноды (call/rag) и так логируют метаданные.
4. **Volume / backpressure.** Synchronous запись на каждую ноду неприемлема для broadcast-нагрузки;
   любой вариант записи — batched/async.
5. **UI.** V1 — без UI (SQL / Filament resource при необходимости). V1.x — dashboard
   «Node Usage» в admin panel.

---

## 5. Тесты (когда определится модель)

- Static SQL view возвращает корректные counts.
- Rollup/счётчики корректно агрегируют (включая flows с выключенным логированием — по выбранному варианту).
- Aggregation queries работают на партиционированных данных.

---

## 6. Связанные документы

- `flow-engine-v1/nodes/11-loop.md` — исходный контекст (Loop addendum)
- CLAUDE.md § «Логирование и retention» — ограничения raw/aggregate уровней
- `flow-engine-v1/09-out-of-scope-and-open-questions.md` — V1.x backlog
