# Introduce fapost/foundation — публичный контрактный пакет для extension boundary

> Архив Notion. Актуальная документация: [[05-foundation-contract-package]]


Depends on: 04
Domain: Module
Phase: 0 — Фундамент
Sprint: 1
Status: Готово
Task №: 4.2

## Цель

Создать отдельный Composer-пакет `fapost/foundation`, который становится единственным публичным контрактным слоем для всех внешних расширений платформы: Solutions, Plugins, будущих внешних интеграций.

## Проблема

Без SDK внешние пакеты вынуждены тянуть весь `fapost/core` ради нескольких интерфейсов. Контракты становятся завязаны на внутреннюю структуру Core, semver-совместимость хрупкая, граница пакета размыта.

## Архитектурное правило

> Если внешний пакет обязан знать контракт → SDK. Если только Core использует его внутри → остаётся в Core.
> 

**SDK содержит:** только публичные контракты платформы.

**Core содержит:** внутренние сервисы, репозитории, runtime-оркестрацию, внутренние реестры, domain internals.

## Создание пакета

Локальный пакет: `packages/fapost-foundation`

Composer name: `fapost/foundation`

Подключение через path repository в Core:

```json
"repositories": [
  { "type": "path", "url": "packages/fapost-foundation" }
]
```

Зависимость Core: `"fapost/foundation": "*"`

## Структура SDK

```jsx
packages/fapost-foundation/
├── src/
│   ├── Contracts/
│   │   ├── ActivatableInterface.php
│   │   ├── CoreRegistrarInterface.php
│   │   ├── NodeHandlerInterface.php
│   │   ├── RagAdapterInterface.php
│   │   ├── DataAccessorInterface.php
│   │   └── ChannelAdapterInterface.php   ← в SDK: плагин может добавлять новый канал
│   ├── Lifecycle/
│   │   ├── AbstractSolutionServiceProvider.php
│   │   └── AbstractPluginServiceProvider.php
│   ├── Manifest/
│   │   └── SolutionManifest.php
│   ├── DTO/
│   │   ├── StructuredRagResult.php
│   │   └── NodeExecutionResult.php
│   └── Support/
│       └── Version.php
└── composer.json
```

## Первые контракты для переноса

**Переносим:**

- `ActivatableInterface`
- `CoreRegistrarInterface`
- `NodeHandlerInterface`
- `RagAdapterInterface`
- `DataAccessorInterface`
- `ChannelAdapterInterface` — плагин как новый канал (Viber, Instagram DM и т.д.)

**Остаются в Core:**

- Tenant-internal контракты
- Flow repositories
- Runtime builders
- Internal registries
- Webhook persistence internals

## Стратегия миграции

1. Создать SDK пакет
2. Перенести только стабильные публичные контракты
3. Заменить импорты внутри Core
4. Нестабильные/внутренние контракты не трогать

## Мандатное правило

С этого момента все новые публичные контракты для расширений создаются **только в SDK**. Запрещено появление внешних контрактов в `app/Domains/*` и `app/Features/*`.

## SolutionManifest

Типизированный PHP-объект манифеста. Поддерживает: `module_id`, `version`, `requires_platform`, `requires_capabilities`. Используется для self-hosted валидации и `platform:update` lifecycle.

## Критерии готовности

- SDK пакет существует локально в `packages/fapost-foundation`
- Core подключает SDK через path repository
- Первые публичные контракты импортируются из SDK
- Новые extension контракты больше не появляются внутри Core domains

## Вне scope первой итерации

- `fapost:make-solution` artisan scaffolding команда
- package template generators
- plugin starter skeleton