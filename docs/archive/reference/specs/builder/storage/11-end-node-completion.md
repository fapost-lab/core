# 11 · End node — dedicated config panel + canvas styling

> **✅ Статус: РЕАЛИЗОВАНО, подход пересмотрен (июнь 2026).** Dedicated `EndConfig.vue` override был сделан, затем снят:
> end-нода переведена на generic schema-driven рендеринг через field-тип `enum-cards` (radio-карточки с icon/hint/accent),
> `EndConfig.vue` удалён (см. `../builder-renderer/05-vendor-glob.md`, часть C). Canvas color-coding по `status`
> (success/cancelled/failed → зелёный/амбер/красный) реализован. Спека ниже описывает исходный bespoke-вариант.

**Зависит от:** —
**Блокирует:** —
**Слой:** builder (Vue)

## Цель

Сделать ноду `end` визуально и поведенчески полноценной. Backend уже полностью реализован (см. аудит) — задача чисто фронтовая: dedicated config override и цветовая дифференциация на canvas по `status`.

## Текущее состояние

- `EndNodeHandler` ([app/Domains/Flow/Handlers/EndNodeHandler.php](../../app/Domains/Flow/Handlers/EndNodeHandler.php)) рабочий. Config: `status: enum(success|cancelled|failed)` default `success`. Сессия закрывается со статусом `Ended`, пишется analytics (`FlowCompleted` / `FlowFailed` / `FlowCancelled`), резюмится parent subflow если применимо.
- В builder — нет `EndConfig.vue` override. Используется generic `SchemaConfigRenderer`, который рендерит status как стандартный dropdown без визуальных подсказок.
- На canvas End рендерится как обычная нода — отличается только меткой типа.

## Scope (минимум)

### 1. `EndConfig.vue` override

```vue
<script setup lang="ts">
import AccordionSection from '../AccordionSection.vue'
// ...
const STATUS_OPTIONS = [
    { value: 'success',   label: 'Success',   icon: '✓', color: 'success' },
    { value: 'cancelled', label: 'Cancelled', icon: '⊘', color: 'warning' },
    { value: 'failed',    label: 'Failed',    icon: '✕', color: 'danger'  },
]
</script>

<template>
    <AccordionSection title="Completion" default-open>
        <div class="end-status-radio">
            <label
                v-for="opt in STATUS_OPTIONS"
                :key="opt.value"
                class="end-status-option"
                :class="[`end-status-option--${opt.color}`, { active: status === opt.value }]"
            >
                <input type="radio" :value="opt.value" :checked="status === opt.value" @change="setStatus(opt.value)">
                <span class="icon">{{ opt.icon }}</span>
                <span class="label">{{ opt.label }}</span>
                <span class="hint">{{ statusHint(opt.value) }}</span>
            </label>
        </div>
    </AccordionSection>
</template>
```

`statusHint(value)`:
- `success` — "Flow finished as expected"
- `cancelled` — "User cancelled or session timed out"
- `failed` — "Flow ended due to error"

Это чтобы автору было понятно, что выбор влияет на analytics, а не просто косметика.

Зарегистрировать в `ConfigPanel.vue` `OVERRIDES`:

```ts
end: EndConfig,
```

### 2. Canvas styling — цветовое отличие по status

В `NodeCard.vue` (или там где рендерится node — найти по `type === 'end'`):
- Добавить класс `node-card--end-{status}`.
- CSS: разные цвета границы и фоновой плашки иконки:
  - `success` → зелёный (`var(--success, #10b981)`)
  - `cancelled` → амбер (`var(--warning, #f59e0b)`)
  - `failed` → красный (`var(--rose, #ef4444)`)
- Иконка ⛳ (или ✓/⊘/✕ соответствующая) в углу карточки.
- Размер карточки чуть компактнее обычной (terminal node, нет content).

Если есть отдельный `EndNodeCard.vue` или специфичные хуки в `NodeCard.vue` — использовать их. Иначе — добавить условный рендер по `node.type === 'end'`.

### 3. Reference

См. `../builder-renderer/03-schema-reference.md` для общего contract'а PHP↔Vue overrides — структура props, registry, store integration.

## Файлы

- `resources/js/builder/components/editor/config/overrides/EndConfig.vue` — новый
- `resources/js/builder/components/editor/ConfigPanel.vue` — добавить `end: EndConfig` в OVERRIDES
- `resources/js/builder/components/builder/NodeCard.vue` (или эквивалент) — стилизация для type='end' по status

## Тестирование

- `npm run build` — сборка чистая.
- Smoke (mental): создать end ноду → выбор каждого из трёх статусов → канва меняет цвет → save+reload → значение сохранено.

## Acceptance

- При выборе End ноды в правой панели рендерится трёх-вариантный radio с иконкой+цветом+подсказкой вместо сухого dropdown.
- На canvas End-нода окрашена по выбранному status (зелёный/амбер/красный).
- Backend поведение не меняется — все existing тесты handler/validator проходят без изменений.

## Out of scope (V1.x)

- `final_message` — финальное сообщение пользователю шаблоном (требует backend изменений в handler).
- `tags` — применение тегов на контакт при завершении.
- `emit_event` — emission internal event для chaining flows.
- `clear_session_state` — очистка `flow.*` перед закрытием.
- Auto-добавление End ноды при создании пустого flow (если когда-нибудь захотим).
- Validation: warning если flow без End, или если несколько End с одинаковым emit_event.

---

## Связано с

- [[10-end]] — спека end ноды
- [[04-session-state-machine]] — state machine
- [[00-overview]] — overview storage

Эти расширения требуют backend changes и заводятся отдельной задачей когда будет реальный продуктовый кейс.
