# Module framework

> Архив Notion. Актуальная документация: [[12-solutions-modules]]


Depends on: 04, 14
Domain: Module
Phase: 4 — Module System & HR
Sprint: 8
Status: К реализации
Task №: 22

## Состав

- `ModuleManifest`: `requires_platform` version range, `requires_capabilities` список
- `ActivatableInterface`
- `CoreRegistrar` полная реализация (поверх `ModuleRegistrarInterface` заглушки из задачи 04)
- Capabilities validation при boot
- Hard fail на несовместимость до начала трафика

## Примечания

> ⚠ Контракты уже определены в задачах 04 и 15 — здесь только реализация.
> 

> ⚠ Открытый вопрос: namespace изоляция ролей модулей, единый manifest contract для SaaS/self-hosted.
>