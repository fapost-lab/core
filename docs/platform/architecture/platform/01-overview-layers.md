# 01 — Обзор архитектуры и слои

## Три слоя продукта

| Слой | Ответственность | Деплой |
| --- | --- | --- |
| **SaaS-оболочка** | Тенанты, биллинг, планы, feature flags | Только облако |
| **Платформа** | Пользователи, боты, flow engine, рассылки, конструктор | SaaS + self-hosted |
| **Модули (Solutions)** | Нишевые блоки, шаблоны, специфические таблицы | Вместе с платформой |

> ⚠ Платформа не знает о модулях. Модули регистрируют себя через plugin interface при boot.
> 

---

## Ключевые архитектурные принципы

- **Single-tenant self-hosted** = «один тенант создан при установке» — не отдельная архитектурная ветка
- **Schema-per-tenant PostgreSQL** — полная изоляция данных
- **`TenantInterface`** (не конкретный `Tenant`) во всех сигнатурах
- **Solutions** — только внешние composer-пакеты, никогда не `app/Solutions/`

---

## Хранение данных

| БД / Схема | Содержит | Кто пишет |
| --- | --- | --- |
| **landlord (PostgreSQL)** | tenants, plans, subscriptions, feature_flags | Только SaaS-оболочка |
| **tenant_<slug> (schema)** | Всё остальное: users, bots, contacts, flows... | Платформа + модули |
| **Redis** | Webhook registry, hot sessions, locks, очереди | Платформа |

> ⚠ SaaS-оболочка — единственный слой с доступом к landlord БД. Платформа о landlord не знает.
>

---

## Связано с

- [[01-octane-ingress-only]] — ADR-01 (отменён): stateless ingress
- [[12-solutions-modules]] — Solutions и Plugins
- [[05-foundation-contract-package]] — Foundation пакет
- [[ROADMAP]] — дорожная карта платформы
- [[03-id-strategy-ulid]] — ID стратегия (ULID)
- [[diagrams/03-tenant-context]] — диаграмма tenant context