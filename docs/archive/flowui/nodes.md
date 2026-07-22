# Core Nodes v1

## 1. SendMessage

Назначение: отправка сообщения в канал доставки.

### Режимы
- static
- payload

### Static mode
Сообщение конфигурируется вручную:
- content_type
- text
- media_url
- keyboard

### Payload mode
Сообщение берётся из runtime payload:

```json
{
  "type": "text",
  "text": "Выберите сотрудника",
  "keyboard": [
    {"text": "Иванов", "callback": "employee.select:1"}
  ]
}
```

### Изменения в модели

```php
enum SendMessageContentType: string
{
    case Text = 'text';
    case Image = 'image';
    case Document = 'document';
    case Video = 'video';
    case Voice = 'voice';
}
```

keyboard выносится отдельно и не является частью enum.

### Новый mode

```php
enum SendMessageMode: string
{
    case Static = 'static';
    case Payload = 'payload';
}
```

### UI
Text with keyboard остаётся UI alias:
- Text -> content_type=text, keyboard=[]
- Text with keyboard -> content_type=text, keyboard=[...]

---

## 2. Input

Назначение: запрос данных у пользователя.

### Поддерживаемые типы
- text
- number
- email
- phone

### Поведение
- обязательный validator
- обязательное сохранение в store

---

## 3. Condition

Назначение: ветвление по условию.

### Выходы
- true
- false

---

## 4. Switch

Назначение: выбор ветки по значению.

### Пример
- status = new
- status = paid
- status = rejected

---

## 5. Delay

Назначение: задержка перед следующей node.

---

## 6. SetAttribute

Назначение: установка значения поля.

### Пример
```text
contact.name = input.name
```

---

## 7. Transform

Назначение: вычисление нового значения.

### Пример
```text
full_name = first_name + ' ' + last_name
```

---

## 8. Integration

Назначение: HTTP вызов внешнего API.

### Возможности
- GET / POST / PUT / DELETE
- headers
- auth
- body builder

### Ответ
response path extraction через json path.

---

## 9. Action

Назначение: запуск зарегистрированного handler.

### Источник handler
единый registry:
- core
- solution
- plugins

### Пример
```text
send_notification
crm.sync_contact
```

---

## 10. EmitEvent

Назначение: генерация события для запуска других flow.

### Пример
```text
order.created
```

---

## 11. Subflow

Назначение: запуск другого flow.

### Режимы
- wait
- fire_and_forget

### Wait mode
parent flow suspend до завершения child flow.

### Требования
- child flow должен завершаться explicit End node

### Возможности
- subflow может быть промежуточной node
- subflow может быть последней node

---

## 12. End

Назначение: явное завершение flow.

### Причины
- success
- cancelled
- failed

---

## 13. ComposeMessage

Назначение: сборка message payload из данных.

### Использование
- buttons from collection
- list
- dynamic keyboard

### Пример
```json
{
  "type": "text",
  "text": "Выберите пакет",
  "keyboard": [
    {"text": "Basic", "callback": "package.select:1"}
  ]
}
```

Используется вместе с SendMessage(mode=payload)

---

## 14. RAG

Назначение: retrieval из документов.

### Возможности
- source file
- chunks
- vector storage

---

## 15. Prompt (future core-ready)

Назначение: вызов LLM без RAG.

### Использование
- classify
- summarize
- rewrite
