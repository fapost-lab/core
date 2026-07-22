# 12 — Self-hosted операционная модель

## Описание

Self-hosted — отдельная операционная модель, не просто «то же самое у клиента». Клиент управляет инфраструктурой, обновлениями и совместимостью.

---

## platform:update — 5 фаз

| Фаза | Действие |
| --- | --- |
| 1. VALIDATE | Проверить manifest, capabilities, migration conflicts. Fail → abort, ничего не тронуто |
| 2. MIGRATE platform | Только platform/* migrations |
| 3. MIGRATE modules | Пер модуль отдельно. Fail одного → rollback только его |
| 4. BOOT VALIDATION | Собрать registry, проверить capabilities |
| 5. ACTIVATE | Прогреть кеш, переключить трафик |

> ⚠ Rollback per phase — не глобальный откат всего.
> 

---

## Модели деплоя

|  | SaaS | Managed self-hosted | Self-hosted |
| --- | --- | --- | --- |
| SaaS-оболочка | ✓ | — | — |
| Платформа | ✓ | ✓ (у клиента) | ✓ (у клиента) |
| Обновления | Авто | Мы деплоим | `platform:update` |
| Целевой сегмент | Средний бизнес | Enterprise (старт) | Enterprise (зрелость) |

---

## Связано с

- [[14-deploy-models]] — модели деплоя
- [[02-saas-shell]] — SaaS оболочка как альтернатива
- [[01-overview-layers]] — общая архитектура