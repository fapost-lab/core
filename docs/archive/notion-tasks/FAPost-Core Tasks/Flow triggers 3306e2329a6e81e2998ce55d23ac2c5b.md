# Flow triggers

> Архив Notion. Актуальная документация: [[README]]


Depends on: 13, 14
Domain: Flow
Phase: 2 — Flow Engine
Sprint: 5
Status: Готово
Task №: 15

## 

# **Задача: Flow Triggers + TriggerResolver + IncomingMessage pipeline**

## **Цель**

Добавить в Core полноценный слой запуска flow через внешние триггеры.

Flow trigger — это внешний execution contract, который определяет, какой flow должен стартовать при наступлении внешнего события.

FlowEngine не занимается поиском trigger. Он получает уже resolved trigger или активную session.

---

# **1. Таблица flow_triggers**

Создать tenant-level таблицу flow_triggers.

## **Структура**

- id UUID PK
- tenant_id UUID
- bot_id UUID nullable
- flow_id UUID
- type varchar(32)
- is_active boolean default true
- priority int default 100
- config jsonb
- last_run_at timestamp nullable
- next_run_at timestamp nullable
- created_at timestamp
- updated_at timestamp

## **Индексы**

- (tenant_id, type, is_active)
- (bot_id, type, is_active)
- (type, is_active)
- partial index next_run_at for schedule

## **Ограничения**

- type: message / schedule / webhook / api
- config валидируется per trigger type
- bot_id допускается nullable

---

# **2. Trigger config contracts**

## **message**

```
{
  "keywords": ["/start", "help"],
  "match": "exact"
}
```

## **schedule**

```
{
  "cron": "0 9 * * *",
  "target": "all"
}
```

## **webhook**

```
{
  "secret": "hash",
  "method": "POST"
}
```

## **api**

```
{
  "allowed_sources": ["hr"]
}
```

---

# **3. TriggerResolver**

Реализовать отдельный orchestration слой TriggerResolver.

## **Контракт**

```
interface TriggerResolverInterface
{
    public function resolve(TriggerContext $context): ?FlowTrigger;
}
```

## **TriggerContext**

```
final class TriggerContext
{
    public string $type;
    public TenantId $tenantId;
    public ?BotId $botId;
    public array $payload;
}
```

## **Реализация**

TriggerResolver dispatch-ит по типу:

- messageResolver
- scheduleResolver
- webhookResolver
- apiResolver

Через match/switch без логики внутри самого orchestration класса.

---

# **4. MessageTriggerResolver**

Отдельный resolver для text triggers.

## **Поведение**

- получить все активные message triggers tenant + bot
- отсортировать по priority
- проверить match rule
- вернуть первый совпавший trigger

## **Match modes**

- exact
- contains
- regex

## **Ограничение**

На первом этапе допускается DB lookup.

Следующим этапом обязательно Redis cache.

---

# **5. IncomingMessageJob pipeline**

Входящий webhook/job должен идти через отдельный pipeline до FlowEngine.

## **Pipeline**

IncomingMessageJob:

1. установить TenantContext
2. установить BotContext
3. resolve contact
4. взять distributed lock
5. проверить активную session
6. если session есть -> FlowEngine resume
7. если session нет -> TriggerResolver resolve
8. если trigger найден -> FlowEngine start
9. если trigger нет -> fallback / ignore

---

# **6. Boundary rules**

## **TriggerResolver не делает:**

- запуск flow
- создание session
- mutation state

## **FlowEngine не делает:**

- поиск trigger
- parsing webhook payload
- transport decisions

## **IncomingMessageJob отвечает только за orchestration**

---

# **7. Следующий обязательный этап после реализации**

После запуска flow_triggers потребуется:

- Redis trigger cache
- Trigger invalidation on save/update/delete
- DTO слой вместо прямой передачи Eloquent models внутрь engine
- ScheduleTrigger executor
- WebhookTrigger endpoint contract

---

# **8. Архитектурный запрет**

Нельзя переносить trigger lookup внутрь FlowEngine.

Причина:

Это смешивает ingress layer и execution layer.

Дальше это ломает расширяемость schedule/api/webhook trigger моделей.