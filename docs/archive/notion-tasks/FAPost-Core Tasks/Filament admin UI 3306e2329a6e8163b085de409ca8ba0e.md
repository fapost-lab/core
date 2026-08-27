# Filament admin UI

> Архив Notion. Актуальная документация: [[01-overview-layers]]


Depends on: 09, 16
Domain: Staff
Phase: 3 — Messaging Pipeline
Sprint: 7
Status: К реализации
Task №: 21

## Состав

- Bots resource
- Contacts resource
- Flow definitions list
- Analytics dashboard (только `analytics_events`)
- Flow sessions monitor

## UI Non-interference Contract

> ✕ Никаких новых методов на доменных объектах ради UI.
> 

> ✕ UI не диктует backend shape до стабилизации runtime model.
> 

> ✕ Filament UI читает только из `analytics_events` и существующих query scopes.
> 

# **Production wireframe — linear flow builder**

## **1. Общий экран**

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ Flow: Employee onboarding                            Draft • v12  [Publish] │
├──────────────────────────────────────────────────────────────────────────────┤
│ Trigger: /start   Bot: HR Assistant   Updated: 16 Apr 2026                  │
├───────────────┬───────────────────────────────────────────┬──────────────────┤
│ Structure     │ Flow sequence                             │ Config           │
│               │                                           │                  │
│ Main flow     │ [ Trigger: /start ]                       │ Node: Input      │
│ ├ Node 1      │          ↓                                │ ---------------- │
│ ├ Node 2      │ [ 1. Send message ]                       │ Label            │
│ ├ Node 3      │          ↓                                │ Full name        │
│ │ ├ yes       │ [ + Add block ]                           │                  │
│ │ └ no        │          ↓                                │ Expected type    │
│ └ Node 4      │ [ 2. Input: full_name ]                   │ text             │
│               │          ↓                                │                  │
│               │ [ 3. Condition: department == HR ]        │ Validation       │
│               │      [yes] [no]                           │ required         │
│               │          ↓                                │ min: 2           │
│               │ [ 4. Send message ]                       │                  │
│               │          ↓                                │ Timeout          │
│               │ [ 5. Assign manager ]                     │ 24h              │
│               │          ↓                                │                  │
│               │ [ End ]                                   │ Save to          │
│               │                                           │ flow.full_name   │
└───────────────┴───────────────────────────────────────────┴──────────────────┘
```

---

## **2. Левая колонка: Structure**

Назначение:

- быстрый jump по дереву
- понимание где пользователь сейчас
- навигация по branch без перегруза canvas

```
Main flow
├─ 1. Send message
├─ 2. Input: full_name
├─ 3. Condition: department == HR
│  ├─ yes
│  │  ├─ 4. Send message
│  │  ├─ 5. Assign manager
│  │  └─ 6. End
│  └─ no
│     ├─ 4. Send message
│     └─ 5. End
└─ 4. Notify staff
```

Что важно:

- tree view, не граф
- текущая ветка подсвечена
- collapse/expand у condition и switch
- drag-and-drop только внутри списка/ветки, не свободный canvas

---

## **3. Центральная колонка: sequence editor**

Это основной рабочий экран.

### **Карточка обычной ноды**

```
┌ Send message ─────────────────────────────────────────────┐
│ Welcome message                                           │
│ Text: Welcome to the company                              │
│ Buttons: 2                                                │
│ Outputs: default                                          │
└───────────────────────────────────────────────────────────┘
```

### **Между нодами всегда есть insert point**

```
[ 1. Send message ]
      + Add block here
[ 2. Input ]
```

Это лучше, чем кнопка только внизу. Пользователь должен вставлять блок в нужное место без reorder-страданий.

---

## **4. Condition node**

Вместо показа двух веток сразу — focus mode.

```
┌ Condition ────────────────────────────────────────────────┐
│ flow.department == "hr"                                  │
│ Branches: YES (3) • NO (2)                               │
│ [ yes ] [ no ]                                            │
└───────────────────────────────────────────────────────────┘
```

Ниже рендерится только активная ветка.

```
Breadcrumb: Main flow / Condition #3 / YES

[ 4. Send message ]
      + Add block here
[ 5. Assign manager ]
      + Add block here
[ 6. End ]
```

Это убирает визуальный мусор.

---

## **5. Switch node**

Для switch не tabs, а accordion + counters.

```
┌ Switch: flow.role ────────────────────────────────────────┐
│ manager (4)                                               │
│ employee (3)                                              │
│ contractor (2)                                            │
│ default (1)                                               │
└───────────────────────────────────────────────────────────┘
```

Открыта только одна ветка.

---

## **6. Правая панель: config**

Правая панель всегда контекстная. Не modals.

### **Для Input**

```
Node: Input
Type: input@v1

Label
[ Full name ]

Save to
[ flow.full_name ]

Expected type
[ text ▼ ]

Validation
[x] required
[ ] regex
Min length [ 2 ]
Max length [ 80 ]

On invalid
Message: [ Please enter valid name ]
Retry limit: [ 3 ]
On exceed: [ invalid branch ▼ ]
```

### **Для Send message**

```
Node: Send message
Type: send_message@v1

Message type
[ text ▼ ]

Body
[ Welcome, {{flow.full_name}} ]

Buttons
+ Add button

Output
default → Node #5
```

---

## **7. Верхняя панель**

```
Flow: Employee onboarding
Status: Draft
Version: 12
Last published: v11

[ Preview ] [ Validate ] [ Save draft ] [ Publish ]
```

Нужны именно эти действия:

- Preview
- Validate
- Save draft
- Publish

Без них редактор будет игрушечным.

---

## **8. Validation panel**

Отдельный блок снизу или slide-over.

```
Validation
- Node 3: condition reads unknown key module.hr.level
- Node 5: output default is not connected
- Node 7: template uses missing variable flow.name
```

Очень важно: редактор должен валидировать не только форму, а execution contract.

---

## **9. Preview mode**

Нужен режим имитации диалога.

```
┌ Preview chat ────────────────────────────┐
│ Bot: Welcome                             │
│ User: John                               │
│ Bot: Select department                   │
│ User: HR                                 │
│ Bot: Great, let's continue               │
└──────────────────────────────────────────┘
```

Это сильно снижает число ошибок контент-менеджера.

---

## **10. Что делать с delay / webhook / handler**

### **Delay**

```
Delay: 2h
Then → Node 8
```

### **Webhook**

```
Webhook: POST /candidate/sync
Payload template: {...}
Success → Node 9
Error → Node 10
```

### **Handler**

```
Handler: hr.sync_employee
Success → Node 11
Error → Node 12
```

То есть даже сложные ноды визуально остаются карточками списка, а не спец-виджетами на canvas.

---

## **11. Минимальный production UX набор**

Нужно сразу заложить:

- insert between nodes
- duplicate node
- move up/down within branch
- collapse subtree
- branch breadcrumb
- validation panel
- preview mode
- draft/publish split
- version read-only info

Без этого редактор будет слабым даже если выглядит красиво.

---

## **12. Чего не делать на первом этапе**

Не делать:

- free drag canvas
- arbitrary edge connections
- zoom / minimap
- multi-branch open at once
- complex auto-layout
- visual bezier edges
- node placement coordinates as main UX

Это дорого и почти не даёт бизнес-ценности на старте.

---

## **13. Архитектурно правильная модель**

Итоговая модель:

- данные исполнения — tree/graph contract как у engine
- UI редактирования — guided linear tree editor
- при необходимости later можно добавить graph view как secondary visualization

Не наоборот.

---

## **Вывод**

Основной экран должен быть **tree-driven linear builder**, а не canvas editor.

Это лучше по трём причинам:

1. ближе к mental model пользователя
2. дешевле в разработке и поддержке
3. меньше конфликтует с вашим execution contract