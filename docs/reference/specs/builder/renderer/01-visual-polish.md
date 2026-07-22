# 01 · Generic renderer — visual polish

> **Статус: РЕАЛИЗОВАНО.** `Schema`/`Section` поддерживают `->icon()` и `->collapsed()`; `SchemaConfigRenderer.vue`
> рендерит секции с иконками (`sectionIcons.ts`) + auto-Meta; `AccordionSection.vue` принимает `icon`/`default-open`.
> Call/RagQuery/EmitEvent/Subflow имеют секции в `configSchema()`. Тест: `NodeHandlerSchemaSectionsTest`.

**Длительность:** 0.5–1 день
**Зависит от:** —
**Блокирует:** —
**Слой:** builder (Vue) + PHP `configSchema()` updates

## Цель

Привести панель конфигурации для нод без bespoke override к стилистике override-нод. Сейчас generic-формы — плоский список input'ов; после задачи — формы с цветным хэдером (иконка + название типа ноды) и секциями (`AccordionSection` с иконкой и заголовком), как у `SendMessageConfig`.

## Текущее состояние

```vue
<!-- SchemaConfigRenderer.vue -->
<AccordionSection title="Configuration" default-open>
    <div v-for="field in fields" :key="field.key" class="config-field">
        <!-- field render -->
    </div>
</AccordionSection>

<AccordionSection title="Meta">
    <!-- Node ID -->
</AccordionSection>
```

Все поля схемы — в одной секции `Configuration`. Без иконок, без логического разбиения. Хэдер ноды (`ConfigPanel.vue`) уже использует `nodeColors(type)` — иконку + цвет; это работает для всех нод одинаково. Проблема не в хэдере, а в теле формы.

## Что меняем

### 1. Расширить `configSchema()` PHP-handler'ов поддержкой sections

Текущий формат:
```php
public function configSchema(): array
{
    return [
        'required' => [...],
        'url'      => ['type' => 'string', 'label' => 'URL', ...],
        'timeout'  => ['type' => 'number', ...],
        // ...
    ];
}
```

Новый формат с опциональным `sections`:
```php
public function configSchema(): array
{
    return [
        'required' => ['url'],
        'sections' => [
            [
                'key'    => 'connection',
                'label'  => 'Connection',
                'icon'   => 'globe',          // Heroicon name (без prefix)
                'fields' => ['url', 'method', 'timeout'],
            ],
            [
                'key'    => 'response',
                'label'  => 'Response handling',
                'icon'   => 'arrow-down-tray',
                'fields' => ['save_response_to', 'success_when', 'success_statuses'],
            ],
            [
                'key'      => 'advanced',
                'label'    => 'Advanced',
                'icon'     => 'cog-6-tooth',
                'fields'   => ['headers', 'retry'],
                'collapsed' => true,             // секция свёрнута по умолчанию
            ],
        ],
        'url'              => ['type' => 'string', ...],
        'method'           => ['type' => 'enum', 'options' => [...], ...],
        'timeout'          => ['type' => 'number', ...],
        'save_response_to' => ['type' => 'state-picker', ...],
        // ...
    ];
}
```

Если `sections` отсутствует — fallback на текущее поведение (одна секция «Configuration»). Полная backward compat.

### 2. Обновить `SchemaConfigRenderer.vue`

```ts
const fieldsByKey = computed<Map<string, FieldEntry>>(() => {
    const map = new Map<string, FieldEntry>()
    for (const field of fields.value) {
        map.set(field.key, field)
    }
    return map
})

const sections = computed<RenderedSection[]>(() => {
    const raw = (props.schema ?? {}) as Record<string, unknown>
    const declared = Array.isArray(raw.sections) ? raw.sections : null

    if (declared !== null) {
        return declared.map((s) => ({
            key:       s.key,
            label:     s.label,
            icon:      s.icon ?? null,
            collapsed: Boolean(s.collapsed),
            fields:    (s.fields ?? []).map((k) => fieldsByKey.value.get(k)).filter(Boolean),
        }))
    }

    // Fallback: одна секция со всеми полями (текущее поведение).
    return [{
        key:       'configuration',
        label:     'Configuration',
        icon:      null,
        collapsed: false,
        fields:    fields.value,
    }]
})
```

В template — итерация по `sections` вместо одной фиксированной секции. Каждая секция → `AccordionSection` с label + icon + collapsed-state.

### 3. Расширить `AccordionSection.vue`

**Каждая секция в форме — это accordion** (collapsible независимо от соседей). Базовое требование UX: длинные конфиги (call с 8+ полями) не помещаются на экране плоско, автор должен иметь возможность свернуть неактуальные сейчас секции.

Поведение:
- Каждая секция — отдельный accordion item. Клик по хэдеру разворачивает/сворачивает её (collapse/expand независим у соседей — не accordion-радиогруппа).
- **Дефолтное состояние** задаётся в schema через `collapsed: true|false`. Если поле не указано — секция развёрнута (`collapsed = false`). Sensible defaults для нашей задачи: `Connection` / `Query` / основные секции — открыты, `Advanced` — свёрнут.
- Состояние **не персистится** между открытиями ноды (V1) — каждый раз начинается с дефолта из schema. Persistence per-user/per-node (через localStorage с ключом `(node_type, section_key)` или `(node_id, section_key)`) — V1.x backlog.
- В свёрнутом состоянии хэдер показывает label + иконку + chevron-индикатор (▶). В развёрнутом — chevron вниз (▼).

API компонента после расширения:

```vue
<AccordionSection
    :title="section.label"
    :icon="section.icon"
    :default-open="!section.collapsed"
>
    <!-- fields -->
</AccordionSection>
```

Props:
- `title: string` — заголовок секции (existing).
- `icon?: string` — Heroicon name (новое).
- `default-open: boolean` — начальное состояние, по умолчанию `true` (existing prop, переиспользуется; через `!section.collapsed`).

Внутреннее состояние компонента — локальный `ref<boolean>` инициализируется из `default-open`, переключается по клику в хэдере. Никакого внешнего управления (open/close через v-model) в V1 не нужно.

### 4. Иконки

Использовать Heroicon (тот же набор, что в Filament-навигации). Маппинг строковых имён → Vue-компонент через статичный реестр или dynamic import (см. как сейчас сделано в `nodeColors`).

Если в проекте нет лёгкого Heroicon-компонента — добавить минимальный (через `<svg>` inline на основе heroicons/vue, либо через текстовые emoji-fallback'и `🌐`, `📥`, `⚙` если приоритет — скорость).

### 5. Обновить `configSchema()` в 6 нодах

Для каждой из `subflow`, `rag_query`, `emit_event`, `delay`, `end`, `call` — добавить `sections` в схему. Минимум 2-3 секции на ноду:

| Нода | Предлагаемые секции |
|------|---------------------|
| `call` | Connection / Response handling / Advanced |
| `rag_query` | Knowledge base / Query / Options |
| `emit_event` | Event / Payload |
| `subflow` | Target flow / Behavior |
| `delay` | (одна секция Schedule достаточно) |
| `end` | (одна секция Outcome достаточно) |

Для нод с 1-2 полями (`delay`, `end`) можно оставить без секций — fallback покроет.

## Файлы

- `resources/js/builder/components/editor/config/SchemaConfigRenderer.vue` — секции + iteration
- `resources/js/builder/components/editor/config/AccordionSection.vue` — prop icon + collapsed
- `resources/js/builder/components/editor/config/SectionIcon.vue` (новый, опц.) — обёртка вокруг Heroicon
- `app/Domains/Flow/Handlers/CallNodeHandler.php` — sections
- `app/Domains/Flow/Handlers/RagQueryNodeHandler.php` — sections
- `app/Domains/Flow/Handlers/EmitEventNodeHandler.php` — sections
- `app/Domains/Flow/Handlers/SubflowNodeHandler.php` — sections
- (delay, end — опционально)

## Acceptance criteria

- Открываю call-ноду → форма разделена на 3 секции (Connection / Response handling / Advanced) с иконками и заголовками. Advanced свёрнут по умолчанию.
- Старые ноды без `sections` в схеме → рендерятся как раньше (одна секция Configuration).
- Иконка секции консистентна с Heroicon set'ом, который использует Filament — визуальное единство админки.
- Phase 1 features (required marker, help text, default injection, json/state-picker fields) продолжают работать без регрессии.

## Риски

- **Heroicon dependency** — если в builder'е сейчас нет общего Heroicon-компонента, его регистрация — мини-задача внутри. Альтернатива: emoji в `icon` поле (`'🌐'`, `'📥'`) — кратчайший путь, но визуально слабее.
- **Расходимость section schema между PHP и Vue** — добавить unit тест на стороне PHP что `configSchema['sections'][*]['fields']` ссылаются на существующие field-keys схемы, иначе renderer молча скроет поле. Простая validation в `FlowDefinitionValidator` или новый `NodeSchemaValidator`.

## Out of scope

- Per-section permissions / conditional show (это Phase 2).
- Editor для секций «через UI» — секции авторятся в коде PHP-handler'а, не в drag&drop.

---

## Связано с

- [[00-overview]] — обзор renderer
- [[02-phase-2-field-types]] — следующая фаза
