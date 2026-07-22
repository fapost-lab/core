# 03 — Пользователи (Staff)

## Описание

**Staff** — люди, которые логинятся в панель управления. Не путать с **Contact** (сотрудники, общающиеся с ботом через мессенджер).

---

## Схема

| Компонент | Описание |
| --- | --- |
| **users** | email, password, tenant_id |
| **roles** | name, priority, is_system |
| **permissions** | Укрупнённые блоки: manage_*, view_* |
| **user_roles** | Pivot: user ↔ role |

---

## Системные роли

| Роль | Priority | Scope |
| --- | --- | --- |
| `admin` | 100 | Полный доступ |
| `content_manager` | 50 | Flow, broadcast, RAG, контакты (без мета) |
| `analyst` | 30 | Аналитика — read-only |

---

## Иерархия ролей

`RoleEnum` — единственный источник истины для системных ролей. `RoleSeeder` читает через `CoreRegistrar::getRoles()` — в Фазе 1 возвращает `RoleEnum::cases()`, в Фазе 4 — + роли модулей.

Нельзя назначить роль с priority ≥ своего. Себе роль нельзя менять никогда.

---

## Деактивация (is_active)

- `is_active = false` → немедленная инвалидация сессии
- `EnsureUserIsActive` middleware на каждый запрос — 401 даже с живым токеном
- Нельзя деактивировать единственного admin и себя

---

## Связано с

- [[04-assistant-domain]] — Assistants, к которым привязаны staff
- [[00-overview]] — auth specs (права доступа)
- [[notify]] — Staff как получатели notify ноды
- [[02-assistant-panel-console]] — ADR панели ассистента