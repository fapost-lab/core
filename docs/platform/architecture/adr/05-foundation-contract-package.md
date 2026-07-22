# ADR-05: fapost/foundation — публичный контрактный пакет для extension boundary

## Контекст

Внешние пакеты (Solutions, Plugins) должны знать публичные контракты платформы, но не должны зависеть от полного `fapost/core` runtime.

Без отдельного пакета контрактов:

- внешние пакеты вынуждены тянуть весь Core
- контракты завязаны на внутреннюю структуру Core
- semver-совместимость хрупкая
- граница пакета размыта

Аналогично — Eloquent-примитивы (трейты, BaseModel) исторически жили в `App\Domains\Shared\`. Solutions и будущие пакеты должны использовать эти примитивы без зависимости на `App\` namespace Core.

## Решение

Три пакета с разными обязанностями:

| Пакет | Назначение | Принцип |
| --- | --- | --- |
| `fapost/foundation` | Публичные контракты расширения | interfaces / DTOs / lifecycle contracts |
| `fapost/support` | Переиспользуемые Eloquent-примитивы | traits / base classes / model concerns |
| Core (`fapost/core`) | Runtime реестры и оркестрация | registry / tenant context / activation runtime |

**Правило foundation:** если внешний пакет обязан знать контракт → foundation. Если только Core использует его внутри → остаётся в Core.

**Правило support:** класс можно подключить в отдельный Laravel package без Core runtime → support. Если внутри есть `register()` / `freeze()` / `resolve()` / mutable state → Core.

## Публичные контракты в fapost/foundation

- `ActivatableInterface`
- `CoreRegistrarInterface`
- `NodeHandlerInterface`
- `RagAdapterInterface`
- `DataAccessorInterface`
- `ModelUtilityInterface` — Solution может реализовывать utility-классы для Core-моделей
- `ModelAttributeResolverInterface` — read-only контракт для resolved computed attributes; Core биндит на `ModelAttributeRegistry`
- `ChannelAdapterInterface` — Plugin может добавлять новый канал (Viber, Instagram DM)

## Что живёт в fapost/support

```jsx
packages/fapost-support/src/
├── Concerns/
│   ├── HasUlidPrimaryKey.php          ← ADR-03 ULID PK trait
│   ├── HasComputedAttributes.php      ← зависит от ModelAttributeResolverInterface
│   ├── InteractWithBuilder.php        ← кастомный Eloquent builder
│   └── InteractWithUtilities.php      ← делегирование методов utility-классу
└── Models/
    └── BaseModel.php                  ← агрегирует все три трейта
```

`App\Domains\Shared\Concerns\*` и `App\Domains\Shared\Models\BaseModel` — deprecated shell-классы, делегируют в `FAPost\Support\*`. Существуют только для backward compatibility.

## Почему HasComputedAttributes в support, а не в Core

Трейт теперь зависит от `ModelAttributeResolverInterface` (foundation-контракт), а не от конкретного `ModelAttributeRegistry` (Core-класс). Core биндит интерфейс:

```php
$this->app->singleton(ModelAttributeResolverInterface::class, ModelAttributeRegistry::class);
```

Это разрывает прямую зависимость support → Core.

## Почему registries остаются в Core

`ModelAttributeRegistry` и `ModuleNamespaceRegistry` содержат `register()` + `freeze()` + mutable extension state — по определению Core runtime. Никогда не переносить в support.

## Структура пакетов

```
packages/fapost-foundation/src/
├── Contracts/          ← публичные интерфейсы (в т.ч. ModelUtilityInterface, ModelAttributeResolverInterface)
├── Lifecycle/          ← AbstractSolutionServiceProvider, AbstractPluginServiceProvider
├── Manifest/           ← SolutionManifest
├── DTO/                ← StructuredRagResult, NodeExecutionResult, IncomingMessage…
└── Support/            ← Version

packages/fapost-support/src/
├── Concerns/           ← HasUlidPrimaryKey, HasComputedAttributes, InteractWithBuilder, InteractWithUtilities
└── Models/             ← BaseModel
```

## Dependency graph

```
fapost/support
    ↑ использует
fapost/foundation  (contracts only)

Core → foundation  (биндит контракты на реализации)
Solutions → foundation  (только контракты)
Plugins → foundation  (только контракты)

Core registries (ModelAttributeRegistry, ModuleNamespaceRegistry) → NEVER in support
```

## Связанные задачи

- Задача 4.2 — реализация fapost/foundation
- Задача 4.1 — BaseModel extension infrastructure (теперь в fapost/support)
- Задача 21 — Module framework использует SDK контракты

## Последствия

- Core импортирует оба пакета через path repository локально, в будущем публикуются на Packagist
- `App\Domains\Shared\*` — deprecated, будет удалён в рамках финального cleanup
- Solutions и Plugins зависят только от `fapost/foundation`; для Eloquent-примитивов — от `fapost/support`
- Фактически разделяет: public contracts (foundation), reusable primitives (support), runtime orchestration (core)

---

## Связано с

- [[12-solutions-modules]] — Solutions и Plugins используют Foundation контракты
- [[01-overview-layers]] — место Foundation в архитектуре
- [[03-node-handler-interface]] — NodeHandlerInterface из Foundation
- [[specs/flow-engine/03-node-handler-interface]] — финальный контракт NodeHandlerInterface