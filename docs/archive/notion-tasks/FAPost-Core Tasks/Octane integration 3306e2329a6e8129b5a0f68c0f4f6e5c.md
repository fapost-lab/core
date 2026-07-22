# Octane integration

> Архив Notion. Актуальная документация: [[01-octane-ingress-only]]


Depends on: 08
Domain: Tenancy
Phase: 1 — Core Platform
Sprint: 3
Status: Готово
Task №: 8.1

> ⚠️ Scope задачи изменён. См. **ADR-01 — Octane: ingress-only**.
> 

---

## Architectural Decision

Octane используется **только для webhook ingress** (`/webhooks/*`).

Главное приложение (`/admin`, `/assistant`, API) работает на **PHP-FPM**.

Причина: система содержит mutable scoped context (`TenantContext`, `CurrentAssistant`). Запуск полного Octane runtime создаёт риск state leakage при нарушении scoped lifecycle в любой точке.

---

## Scope этой задачи (сужен)

| Было | Стало |
| --- | --- |
| Octane поверх всего приложения | Octane только для `/webhooks/*` |
| Тестирование scoped bindings во всех панелях | Только webhook ingress pipeline |
| `runForTenant` как изолятор для всех запросов | `runForTenant` только внутри webhook handler |

---

## Что реализовать

### 1. RoadRunner config для webhook ingress

Отдельный `rr-webhook.yaml` или секция в основном конфиге:

- Обслуживает только `/webhooks/*`
- Отдельный worker pool от основного приложения

### 2. Routing split (Traefik / Nginx)

```
/webhooks/* → octane upstream (RoadRunner)
все остальное → php-fpm upstream
```

### 3. Проверка stateless-ности webhook path

Убедиться что весь путь `receive → validate → resolve → dispatch → 200` не накапливает состояние между запросами:

- `TenantSwitcher::runForTenant()` с `finally restore()` — обязателен
- Нет singleton bindings в webhook path
- Нет session, нет Filament

### 4. Тесты

`tests/Feature/Webhook/OctaneWebhookLifecycleTest.php`

- `test_tenant_context_reset_between_requests`
- `test_no_state_leakage_after_concurrent_webhooks`
- `test_run_for_tenant_restores_context_on_exception`

---

## Что НЕ входит в задачу

- Octane для `/admin` — намеренно исключено
- Octane для `/assistant` — намеренно исключено
- Проверка scoped bindings в Filament контексте — не нужна, Filament на PHP-FPM

---

## Ссылки

- ADR: **ADR-01 — Octane: ingress-only** (Архитектура платформы)
- Webhook pipeline: задача 08 — Webhook routing