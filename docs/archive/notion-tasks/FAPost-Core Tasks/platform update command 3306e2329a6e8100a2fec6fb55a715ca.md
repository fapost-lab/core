# platform:update command

> Архив Notion. Актуальная документация: [[01-overview-layers]]


Depends on: 22
Domain: Ops
Phase: 5 — Self-hosted & SaaS
Sprint: 10
Status: К реализации
Task №: 26

## Состав

5 фаз с изолированным rollback:

| Фаза | Действие |
| --- | --- |
| 1. VALIDATE | Проверить manifest, capabilities, migration conflicts. Fail → abort, ничего не тронуто |
| 2. MIGRATE platform | Только platform/* migrations. Fail → rollback platform → abort |
| 3. MIGRATE modules | Пер модуль отдельно. Fail одного → rollback только его |
| 4. BOOT VALIDATION | Собрать registry, проверить capabilities |
| 5. ACTIVATE | Прогреть кеш, переключить трафик |

## Открытые вопросы

> ⚠ Cross-module migration enforcement: linter в CI или дополнительный runtime check?
> 

> ⚠ Единый manifest contract: адаптация валидации SaaS vs self-hosted?
>