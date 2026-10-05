# 03 · SendMessage button save_to — миграция на VariableStorageEditor

**Зависит от:** 01, 02 (compiler/decompiler утилиты)
**Блокирует:** —
**Слой:** builder (Vue) + handler (PHP)

## Цель

В `SendMessage` с inline-keyboard сейчас есть блок «Save answer to» (Type select + plain input). Имя переменной всегда префиксится `flow.` в `SendMessageNodeHandler:297`. Заменить на `VariableStorageEditor`, дать выбор Contact/Temporary, добавить Group.

## Текущее состояние

```vue
<!-- SendMessageConfig.vue:230-252 -->
<div class="config-field">
    <div class="field-label">
        Save answer to
        <span class="field-hint">flow.<em>variable</em></span>
    </div>
    <div class="save-to-row">
        <select :value="saveToType" @change="...">
            <option value="string">String</option>
            <option value="number">Number</option>
            <option value="boolean">Boolean</option>
        </select>
        <input :value="config.save_to" placeholder="e.g. menu_choice" @input="...">
    </div>
</div>
```

В backend (`SendMessageNodeHandler:296-297`):
```php
if (is_string($config['save_to'] ?? null)) {
    $stateChanges["flow.{$config['save_to']}"] = (string)($button['value'] ?? '');
}
```

## Что меняем

### 1. UI

```vue
<VariableStorageEditor
    v-model="answerVariable"
    :type-options="BUTTON_VALUE_TYPES"
    :known-groups="knownGroups"
    show-storage
    show-group
/>
```

`BUTTON_VALUE_TYPES`:
```ts
const BUTTON_VALUE_TYPES = [
    { value: 'text',    label: 'Text' },
    { value: 'number',  label: 'Number' },
    { value: 'confirm', label: 'Yes/No' },  // (boolean)
]
```

(Или маппим `confirm` ↔ существующий `boolean` тип в snapshot — выбирается по UX, не критично.)

### 2. Compilation

UI → snapshot:
```json
{
    "type": "send_message",
    "config": {
        "content_type": "text_with_keyboard",
        "text": "...",
        "buttons": [...],
        "save_to_variable": {
            "name": "menu_choice",
            "type": "text",
            "storage": "contact",
            "group": null
        }
    }
}
```

Имя поля изменилось: было `save_to` + `save_to_type`, стало единый блок `save_to_variable`.

### 3. Decompilation legacy

При загрузке:
- `save_to_variable` есть → use as is.
- Иначе: `save_to: "menu_choice"` + `save_to_type: "string"` → `{ name: 'menu_choice', type: 'text', storage: 'session', group: null }` (legacy всегда был `flow.*`).

### 4. Backend

`SendMessageNodeHandler::execute`:
- Если есть `save_to_variable` → построить target path через VariableResolver (задача 05).
- Иначе legacy `save_to` → как сейчас (префикс `flow.`).

```php
$variable = $config['save_to_variable'] ?? null;
if (is_array($variable)) {
    $path = $variableResolver->resolveTargetPath($variable);
    $stateChanges[$path] = (string)($button['value'] ?? '');
} elseif (is_string($config['save_to'] ?? null)) {
    $stateChanges["flow.{$config['save_to']}"] = (string)($button['value'] ?? '');
}
```

## Файлы

- `resources/js/builder/components/editor/config/overrides/SendMessageConfig.vue` — заменить save_to блок
- `app/Domains/Flow/Handlers/SendMessageNodeHandler.php` — поддержать `save_to_variable`
- Schema validators (если есть)

## Acceptance

- Открываю существующий flow с кнопками + `save_to: "menu_choice"` → editor показывает Name=`menu_choice`, Type=Text, Save to=⏱ Temporary, Group=—.
- Меняю на 💾 Contact + Group=`survey` → save → reload → правильно прочиталось.
- Runtime: нажатие кнопки в новой форме пишет в `contact.attributes.survey.menu_choice`. В legacy форме — в `flow_sessions.state.flow.menu_choice`.
- Reply-keyboard scenario (`isReplyKeyboard`): save_to блок скрыт (так и было).

---

## Связано с

- [[00-overview]] — overview storage
- [[01-send-message]] — спека send_message ноды
- [[01-variable-storage-editor]] — Variable Storage Editor

## Risks

- Двойное имя поля в JSON (`save_to_variable` vs `save_to`) — нужно явно документировать в FlowDefinitionValidator чтобы оба не пришли одновременно.
