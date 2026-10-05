# ADR-01 — Octane: ingress-only

> **Superseded in part (2026-10).** Octane is gone. The webhook route is `POST /webhook/{channel}/{hash}`
> (not `/webhooks/*`), served by PHP-FPM, with an optional Go gateway in `gateway/` taking over ingress. The worker-safety
> rules cited below now live in `.ai/knowledge/conventions/worker-safety.md` (the old "CLAUDE.md Long-Lived Worker
> Safety" section no longer exists).

> Зафиксировано: март 2026. Статус: **отменено** (август 2026).
>
> Octane удалён из проекта: пакет `laravel/octane`, `config/octane.php` и переменная
> `OCTANE_SERVER` больше не входят в состав Core. Роль быстрого ingress перед PHP
> выполняет Go-гейтвей в `gateway/`.
>
> Текст ниже сохранён как запись решения и его причин. Ограничения на singleton-состояние,
> `scoped` bindings и восстановление tenant context в `finally` остаются в силе —
> они относятся к долгоживущим Horizon-воркерам, а не к Octane. См.
> `.ai/knowledge/conventions/worker-safety.md`.

---

## Решение

Octane используется **только** для webhook ingress. Основное приложение работает на стандартном Laravel lifecycle (PHP-FPM).

---

## Причина

Система содержит mutable runtime context:

- `TenantContext` — scoped, устанавливается per-request
- `CurrentAssistant` — scoped, устанавливается per-request

Запуск полного Octane runtime для всего приложения создаёт высокий риск state leakage при нарушении scoped lifecycle в любой точке.

**Особо опасные зоны:**

- tenant resolution
- assistant resolution
- Filament panel state
- authorization context
- session-driven navigation (AssistantPanel)

---

## Модель деплоя

| Компонент | Runtime | Обслуживает |
| --- | --- | --- |
| **Main app** | PHP-FPM | `/admin`, `/assistant`, API, tenant UI |
| **Webhook ingress** | Octane (RoadRunner) | `/webhooks/*` |

Разделение на уровне Traefik / Nginx: `/webhooks/*` → octane upstream, всё остальное → php-fpm upstream.

---

## Почему webhook ingress безопасен для Octane

Webhook pipeline характеристики:

- Короткий lifecycle
- Нет UI state
- Нет сессии
- Нет Filament
- Нет navigation state
- Нет долгой бизнес-логики внутри request

Webhook path:

```
receive request
  → validate signature
  → resolve tenant
  → resolve channel/assistant
  → idempotency lock
  → dispatch queue job
  → immediate response
```

После `dispatch` — только возврат 200. Вся логика в воркере.

---

## Обязательные ограничения для Octane ingress

1. **Весь webhook path остаётся stateless** — никакого накопленного состояния между запросами
2. **Любой tenant switch — только через `TenantSwitcher::runForTenant()`** с обязательным `finally restore()`
3. **Никакого mutable singleton state** — только scoped bindings
4. **Все runtime context bindings остаются scoped**

---

## Что намеренно исключено из Octane

- Filament panels (`/admin`, `/assistant`)
- Обычные tenant routes
- API routes (пока не аудированы)

---

## Стратегия расширения

Если Octane scope расширяется в будущем — только после **полного lifecycle audit** всех scoped bindings.

До тех пор: **Octane = ingress-only**.

---

## Влияние на задачи

| Задача | Изменение |
| --- | --- |
| **08a — Octane integration** | Scope сужается: только webhook ingress + RoadRunner config. Scoped bindings для `/admin` и `/assistant` — не тестируются в контексте Octane. |
| **06.2 — Assistant Panel** | AssistantPanel работает на PHP-FPM. `CurrentAssistant` — scoped, но Octane lifecycle к нему не применяется. |
| **platform:update (25)** | Deployment guide должен описывать два upstream-а. |

---

## Связано с

- [[01-webhook-pipeline]] — диаграмма webhook pipeline, которую описывает этот ADR
- [[10-message-pipeline]] — архитектура message pipeline
- [[09-message-routing-concurrency]] — маршрутизация и concurrency
- [[08-concurrency-idempotency]] — concurrency и idempotency в платформе
- [[diagrams/01-webhook-pipeline]] — диаграмма webhook pipeline
- [[specs/flow-engine/README]] — обзор flow engine