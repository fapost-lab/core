# ADR-06 — Frontend Extension Boundary: Plugin vs Solution

> **Superseded in part (2026-10).** Two things this ADR describes are not built:
>
> - The `platform:update` command (Task 25) does not exist; the `Platform` commands are `InstallCommand`,
>   `InstallGatewayCommand` and `InstallPlatformCommand`. The "run `npm run build` after installing a Solution" step is a
>   manual deploy step today.
> - Runtime plugin installation from a zip in the admin panel is not implemented either; the Plugin rows describe a target.
>
> The vendor glob for Solution Vue components is implemented in `resources/js/builder/utils/vendorComponents.ts` (static,
> eager `import.meta.glob`; it resolves to empty maps when no Solution is installed).

## Статус

Принято · Апрель 2026

---

## Контекст

Flow builder требует возможности рендерить config panel и карточки нод для расширений. Возникает вопрос: могут ли Plugin и Solution поставлять Vue-компоненты для builder?

Расширения бывают двух типов:

- **Plugin** — устанавливается через zip в админке без деплоя. Runtime расширение.
- **Solution** — устанавливается как composer-пакет. Требует деплоя (deployment-time расширение).

---

## Таблица возможностей

| Capability | Core | Plugin | Solution | SaaS-only |
| --- | --- | --- | --- | --- |
| NodeHandler | ✓ | ✓ | ✓ | ✗ |
| DataAccessor | ✓ | ✓ | ✓ | ✗ |
| Flow template | ✓ | ✓ | ✓ | ✗ |
| Tenant migrations | ✓ | ✗ | ✓ | ✗ |
| Landlord access | ✗ | ✗ | ✗ | ✓ |
| Queue jobs | ✓ | limited | ✓ | ✗ |
| Scheduled jobs | ✓ | limited | ✓ | ✗ |
| Custom Vue config component | ✓ | ✗ | ✓ | ✗ |
| Custom preview renderer | ✓ | ✗ | ✓ | ✗ |
| Custom field renderer | ✓ | ✗ | ✓ | ✗ |
| Filament resources | ✓ | ✗ | ✓ | ✗ |
| Runtime install without deploy | ✗ | ✓ | ✗ | ✗ |
| Composer package | internal | optional | mandatory | internal |

---

## Пояснения к таблице

**Plugin — limited для Queue/Scheduled jobs**

Plugin может диспатчить jobs, но не может регистрировать новые worker pools в Horizon и не может добавлять новые scheduled entries в kernel. Только использование существующих очередей платформы.

**Flow template у Plugin**

Template — это JSON файл без привязки к build pipeline. Plugin может поставлять templates через `vendor:publish` без перебилда frontend.

**Custom preview renderer vs Custom Vue config component**

- **Config component** — правая панель (config panel) при выборе ноды
- **Preview renderer** — карточка ноды в sequence editor (центральная колонка)

Оба требуют перебилда — только Solution может их поставлять.

---

## Решение

### Plugin — только логика, без Vue компонентов

Plugin устанавливается без перебилда frontend. Поэтому Plugin **не может** поставлять Vue-компоненты для builder.

Ноды от Plugin получают рендеринг через schema-driven механизм: handler описывает `configSchema()`, builder рендерит поля автоматически через `SchemaConfigRenderer`. Карточка ноды рендерится дефолтным `FlowNodeCard`.

Если стандартных типов полей недостаточно — расширять нужно поддерживаемые field types в `SchemaConfigRenderer` (Core), а не делать Plugin Solution-ом.

### Solution — может поставлять Vue компоненты (с перебилдом)

Solution устанавливается как composer-пакет. Установка является деплой-событием — перебилд frontend обязателен и ожидаем.

Solution публикует Vue-компоненты через `vendor:publish`:

```
vendor/fapost/solution-{name}/
  resources/
    js/
      builder/
        SyncEmployeeConfig.vue     ← config panel override
        SyncEmployeePreview.vue    ← node card preview override (опционально)
```

Builder подхватывает через Vite glob:

```jsx
// Config overrides
const vendorConfigs = import.meta.glob(
  '../../vendor/fapost/*/resources/js/builder/*Config.vue',
  { eager: true }
)

// Preview overrides
const vendorPreviews = import.meta.glob(
  '../../vendor/fapost/*/resources/js/builder/*Preview.vue',
  { eager: true }
)
```

### Соглашение по именованию

Имя файла определяет тип ноды:

- `SyncEmployeeConfig.vue` → тип `sync_employee` (config panel)
- `SyncEmployeePreview.vue` → тип `sync_employee` (node card preview)

Это единственное соглашение которое Solution обязан соблюдать. Нарушение = компонент не будет подхвачен builder-ом.

---

## Последствия

- Plugin с нетривиальным config UI ограничен типами полей `SchemaConfigRenderer`. Расширять нужно renderer в Core, не делать Plugin Solution-ом.
- Каждый новый Solution после установки требует `npm run build` как часть деплой процедуры. Зафиксировать в `platform:update` (Task 25).
- `vendor:publish` для JS-ресурсов Solution должен быть задокументирован в Module Registration Contract.
- Landlord access — исключительно SaaS-оболочка. Ни Plugin, ни Solution не имеют доступа к landlord БД.

---

## Альтернативы которые были отклонены

**Runtime dynamic import (Б2)** — Solution поставляет скомпилированный JS, builder загружает через dynamic `import()` в runtime. Отклонено: сложность реализации не оправдана, проблемы с CSP и изоляцией.

**Schema-driven с расширенными типами (В)** — Solution не поставляет Vue код, только описывает сложные типы полей в схеме. Отклонено: не покрывает нестандартный UX (превью, вложенные редакторы, специфичный для домена UI).

---

## Связанные решения

- ADR-05: fapost/foundation — публичный контрактный пакет
- Module Registration Contract (Task 04 → 21)
- Task 17: Flow builder implementation
- Task 25: platform:update command

---

## Связано с

- [[00-overview]] — обзор schema renderer (specs/builder/renderer)
- [[05-vendor-glob]] — vendor glob реализация
- [[12-solutions-modules]] — Solutions и Plugins в архитектуре
- [[specs/builder/renderer/]] — schema renderer спека