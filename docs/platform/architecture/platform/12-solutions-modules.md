# 11 — Модули (Solutions)

## Описание

Модуль — PHP-пакет, регистрирует себя через plugin interface при boot. Не меняет код платформы.

---

## Что может регистрировать модуль

- **Node Handlers** — новые типы нод: `sync_employee`, `send_offer`
- **Flow Templates** — готовые flow под нишу
- **Migrations** — собственные таблицы в tenant schema
- **DataAccessors** — реализация `DataAccessorInterface`
- **UI Sections** — Filament resources
- **Scheduled Jobs** — синхронизация, напоминания
- **Roles & Permissions** — через `CoreRegistrar::roles()` / `CoreRegistrar::permissions()`

---

## Module Manifest

```json
{
  "module": "hr",
  "version": "2.1.0",
  "requires_platform": ">=1.4.0 <2.0.0",
  "requires_capabilities": [
    "flow.rag_adapter",
    "flow.node_registry",
    "messaging.broadcast",
    "contacts.attributes"
  ]
}
```

---

## Изоляция отказов

| Уровень | Поведение |
| --- | --- |
| Node handler exception | Session failed + fallback message |
| Module boot fail | Degraded state, новые flow не стартуют, активные доживают |
| Migration fail при update | Rollback только этого модуля |

---

## HR-модуль (`fapost/solution-hr`)

| Таблица | Описание |
| --- | --- |
| **employees** | external_id, contact_id, department, position, status, meta — CANONICAL |
| **employee_sync_logs** | История синхронизаций |
| **assessments** | Оценки 360°: period, status, config |
| **assessment_results** | employee_id, evaluator_id, competency, score, comment |
| **access_control_rules** | Правила автодоступа |

---

## Связано с

- [[05-foundation-contract-package]] — Foundation контракты
- [[06-frontend-extension-boundary]] — ADR frontend расширений
- [[01-overview-layers]] — место в архитектуре
- [[ROADMAP]] — дорожная карта по milestone-ам