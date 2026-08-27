# Node · `branch`

Ветвление по условиям. Объединяет classic Condition (true/false) и Switch (множественные cases).

**Type:** `branch`
**Version:** 1
**Idempotent:** yes (чистое чтение state)

## Config

```json
{
  "cases": [
    {
      "left": {"source": "contact", "path": "form.input1"},
      "operator": "eq",
      "right": {"type": "literal", "value": 1},
      "handle": "first"
    },
    {
      "left": {"source": "contact", "path": "form.input1"},
      "operator": "eq",
      "right": {"type": "literal", "value": 2},
      "handle": "second"
    }
  ],
  "default_handle": "other"
}
```

**Поля:**

| Поле | Тип | Required | Описание |
|------|-----|----------|----------|
| `cases` | array | yes | Список условий, проверяются по порядку |
| `default_handle` | string | yes | Handle когда ни один case не сматчился |

**Case:**

| Поле | Тип | Описание |
|------|-----|----------|
| `left` | OperandRef | Левая часть |
| `operator` | enum | Оператор сравнения |
| `right` | OperandRef\|Literal | Правая часть (отсутствует для unary operators) |
| `handle` | string | Имя handle для перехода |

**OperandRef:**

```json
{"source": "contact",  "path": "form.input1"}
{"source": "flow",     "path": "code"}
{"source": "rag",      "path": "confidence"}
{"source": "call",     "path": "response.status"}
{"source": "module",   "path": "hr.department"}
```

**Literal:**

```json
{"type": "literal", "value": 42}
{"type": "literal", "value": "active"}
{"type": "literal", "value": true}
{"type": "literal", "value": null}
```

## Operators

| Operator | Описание | Применимость | Arity |
|----------|----------|--------------|-------|
| `eq` | равно | любые типы | binary |
| `neq` | не равно | любые | binary |
| `gt` / `gte` | > / >= | numbers, dates | binary |
| `lt` / `lte` | < / <= | numbers, dates | binary |
| `contains` | содержит | strings, arrays | binary |
| `not_contains` | не содержит | strings, arrays | binary |
| `starts_with` / `ends_with` | начинается/заканчивается | strings | binary |
| `in` / `not_in` | входит / не входит в массив | left = scalar, right = array | binary |
| `is_empty` / `is_not_empty` | пусто / не пусто | unary, без `right` | **unary** |
| `is_null` / `is_not_null` | null / не null | unary, без `right` | **unary** |

## Output handles

- Все handles из `cases[].handle` + `default_handle`

## Behavior

1. Для каждого case по порядку — резолвит left и right через resolvers
2. Применяет operator
3. Первый матч — выходит через `case.handle`
4. Если ни один не сматчился — выходит через `default_handle`

## Module accessor failures

> **Patch v1.1:** новый sub-block.

Branch reading через `module.*` operand:
- Если accessor успешно вернул значение (включая null) → comparison выполняется штатно
- Если accessor бросает (module degraded, missing capability, infrastructure error) → **session failed**
- Fallback message из node config (если задан) отправляется контакту
- См. Module Isolated Fail Strategy в Platform Architecture, раздел 12.3

Branch не получает специальный `module_error` handle. Это намеренно — инфраструктурные failures обрабатываются на уровне engine, не node logic.

## Validation flow_definition

- Все handles в cases должны быть unique
- `default_handle` не должен дублировать handle case
- Edges из ноды должны покрывать все объявленные handles (warning при отсутствии edge — flow продолжает идти к default null transition)
- **Unary operators** (`is_empty`, `is_not_empty`, `is_null`, `is_not_null`) — `right` **запрещён** в case
- **Binary operators** (всё остальное) — `right` **обязателен**

---

## Связано с

- [[README]] — nodes README
- [[07-branch-source-picker]] — condition operand picker в builder
- [[05-backend-contract]] — backend контракт для переменных
- [[02-input]] — input часто предшествует branch
