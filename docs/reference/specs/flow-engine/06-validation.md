# 06 · Validation flow_definition

При работе с flow_definition применяются два режима валидации (см. ADR Subflow Composition для обоснования):

- **Save draft** (`is_active = false`): все проверки ниже выполняются как **warnings**. Save всегда успешен; UI показывает warnings рядом с problematic nodes. Draft — рабочее пространство.
- **Publish** (`is_active` false → true): те же проверки, но любое нарушение → 422 с детальным error report и **блокирует** publish.

Это применяется ко всем 6 уровням ниже одинаково.

## 6.1 Structural

- Все ссылки на ноды (edges, parent_resume_node) указывают на существующие node ids
- entry_node_id задан явно и существует
- Хотя бы одна `end` node
- Нет orphan nodes (недостижимые от entry)
- Нет dangling edges (edge target не существует)

## 6.2 Type-level (per node)

- Каждый node.type зарегистрирован в NodeHandlerRegistry
- node.version ∈ supportedVersions() для этого type
- node.config соответствует Config schema этого type@version

## 6.3 Reference

- Subflow.flow_id существует (callable flow)
- Subflow call graph: depth ≤ 3, no cycles (см. [nodes/08-subflow.md](nodes/08-subflow.md))
- knowledge_base_id в rag_query существует и принадлежит тенанту
- call.transport ∈ зарегистрированные транспорты
- call.transport='handler' → target ∈ зарегистрированные actions

## 6.4 State namespace

- save_to / target в input/assign:
  - Начинается с `contact.` или `flow.`
  - Не нарушает reserved keys
  - Глубина ≤ 1 группы для contact
  - В assign — `target` литеральный, без placeholder `{{...}}`
- Operands в branch — source ∈ {contact, flow, rag, call, module}, path валиден
- Branch unary operators (`is_empty`/`is_not_empty`/`is_null`/`is_not_null`) — без `right`; binary — с `right` обязательно

## 6.5 Variable path uniqueness (rewritten)

> **Patch v1.1:** старая формулировка «name unique across all storages» заменена. Новая семантика — на resolved path.

Уникальность нужна на **полном resolved path**, не на имени.

**Procedure:**

1. Собрать все targets записи из Input/Assign nodes (resolved paths)
2. Группировать по path
3. Для каждого пути проверить:
   - Same path multiple writes — **OK** (нормальный паттерн перезаписи / retry input)
   - Конфликт leaf vs group структуры — **error**

### Что разрешено

```
Input → contact.phone     (попытка 1)
Branch → if invalid →
Input → contact.phone     (попытка 2, та же переменная)
```

OK. Один path, две ноды, обе пишут. Стандартный retry pattern.

```
Input → contact.first_name
Assign → contact.first_name = capitalize({{contact.first_name}})
```

OK. Same path, разные nodes, явная transformation.

```
Input → contact.name (storage=contact, group=null)
Assign → flow.name (storage=session, group=null)
```

OK. Разные resolved paths (`contact.name` vs `flow.name`).

### Что блокируется

```
Input → contact.profile           (leaf write)
Assign → contact.profile.name = ... (group write по тому же префиксу)
```

Validation error: `Path 'contact.profile' is used as both leaf and group within flow definition.`

```
Input → contact.form.input1
Input → contact.form              (leaf write на родительский путь)
```

Validation error: тот же конфликт обратно.

### Implementation

```php
final class VariablePathValidator
{
    public function validate(FlowDefinition $def): array
    {
        $paths = $this->collectWriteTargets($def);  // [path => [nodeIds]]
        $errors = [];

        foreach ($paths as $path => $nodes) {
            // Если path записывается как leaf И существуют path.x writes
            if ($this->hasParentLeafPath($paths, $path)) {
                $errors[] = new PathStructuralConflict($path, ...);
            }
        }

        return $errors;
    }
}
```

## 6.6 Edge handles coverage

- Edges из node должны покрывать все handles из NodeHandler::outputHandles()
- Незакрытый handle → warning (не fatal). При runtime выходе через незакрытый handle session попадает в фолбэк logic (engine логирует, помечает sessions failed if no fallback).

---

## Связано с

- [[README]] — flow engine README
- [[../../plans/flow-engine/implementation-plan]] — план реализации
- [[08-acceptance-criteria]] — критерии приёмки
