# Builder page — final production structure

Цель страницы

Builder должен быть:

* tree-driven editor
* snapshot-safe относительно runtime
* расширяемый через solution UI extensions
* предсказуемый для content manager

Главный принцип:
Builder не выглядит как canvas graph editor.
Builder выглядит как управляемый execution editor.

⸻

1. Общий layout страницы

┌──────────────────────────────────────────────────────────────────────────────┐
│ Flow: Employee onboarding Draft v47 • Published v11 │
│ Status: Draft                                    [Validate] [Save] [Publish] │
├───────────────┬───────────────────────────────────────────┬──────────────────┤
│ Structure │ Flow sequence │ Config │
│ │ │ │
│ Main flow │ [ Trigger: /start ]                       │ Node: SendMessage│
│ ├ Node 1 │ ↓ │ ---------------- │
│ ├ Node 2 │ [ 1. Send message ]                       │ Body │
│ ├ Node 3 │ ↓ │ [ textarea ]     │
│ │ ├ yes │ [ + Add block ]                           │ │
│ │ └ no │ ↓ │ Buttons │
│ └ Node 4 │ [ 2. Condition ]                          │ [ repeater ]     │
│ │      [yes] [no]                           │ │
│ │ ↓ │ Timeout │
│ │ [ 3. Send message ]                       │ [ number ]       │
│ │ ↓ │ │
│ │ [ End ]                                   │ │
└───────────────┴───────────────────────────────────────────┴──────────────────┘

⸻

2. Верхняя панель (top bar)

Содержит

Flow name
Draft version
Published version
Status

⸻

Actions

Validate
Save draft
Publish

⸻

Дополнительно позже

Preview
Compare versions
Rollback

⸻

3. Левая колонка — Structure panel

Назначение

Навигация по дереву flow.

Не редактор.

⸻

Формат

Main flow
├─ 1. Send message
├─ 2. Input
├─ 3. Condition
│ ├─ yes
│ └─ no
└─ 4. End

⸻

Поведение

* текущая выбранная нода подсвечена
* condition раскрывается / сворачивается
* click → jump к node в sequence

⸻

Ограничение

Нет drag-and-drop здесь на первом этапе.

⸻

4. Центральная колонка — Flow sequence

Это основной рабочий контекст.

⸻

Базовый pipeline

[ Trigger ]
↓
[ Node ]
↓
[ Node ]

⸻

Между каждой нодой insert point

[ Send message ]

+ Add block here
  [ Input ]

⸻

Почему обязательно

Без insert point пользователь начинает страдать с reorder.

⸻

5. Node card

Каждая нода = summary card.

⸻

Стандартный вид

┌ Send message ─────────────────────────────┐
│ Text: Welcome to company │
│ Buttons: 2 │
│ Outputs: default │
└───────────────────────────────────────────┘

⸻

Что показывает card

* label
* summary config
* outputs summary
* warning badge если config invalid

⸻

Источник summary

Core nodes

custom preview renderer optional

Plugin nodes

generic schema summary

Solution nodes

optional preview component

⸻

6. Condition node

Condition не раскрывает обе ветки сразу.

⸻

Card

┌ Condition ────────────────────────────────┐
│ flow.department == "hr"                  │
│ YES: 3 nodes NO: 2 nodes │
│ [ yes ] [ no ]                            │
└───────────────────────────────────────────┘

⸻

Активная ветка ниже

Breadcrumb: Main flow / Condition #3 / YES
[ Send message ]
[ Assign ]
[ End ]

⸻

Почему так

Иначе sequence превращается в визуальный шум.

⸻

7. Switch node

Только одна открытая ветка

manager (4)
employee (3)
default (1)

Accordion.

⸻

8. Правая колонка — Config panel

Контекстная панель выбранной ноды.

⸻

Universal rendering

По умолчанию schema-driven.

string → input / textarea
number → number field
boolean → switch
select → dropdown
array → repeater
json → json editor

⸻

Schema пример

{
"body": { "type": "string", "label": "Body" },
"timeout": { "type": "number", "label": "Timeout" }
}

⸻

Override renderer

Если node registry содержит renderer:

renderer = send-message

Тогда используется custom config component.

⸻

Правило

Schema mandatory.
Renderer optional.

⸻

9. Extension model

Plugin node

schema only

Config panel рендерится универсально.

⸻

Solution node

schema + optional custom config component
schema + optional preview component

⸻

Ограничение

Ни plugin, ни solution не меняют shell builder.

Запрещено:

* менять tree renderer
* менять branch navigation
* менять publish behavior

⸻

10. Validation UX

После validate ошибки возвращаются с path.

⸻

Пример

{
"path": "nodes.condition_3.outputs.yes",
"message": "Missing target"
}

⸻

UI поведение

* node получает warning badge
* Config panel показывает ошибку

⸻

11. Draft lifecycle

Save draft

Сохраняет flow_drafts.

⸻

Publish

Создаёт новый flow_definitions snapshot.

⸻

Builder всегда работает только с draft

Engine никогда не читает draft.

⸻

12. Что не делать на первом этапе

Не делать:

* canvas drag editor
* arbitrary edges
* minimap
* free positioning
* multi-branch open simultaneously
* advanced graph view

⸻

13. Tabs model

Builder page должна иметь две основные вкладки

[ Builder ]   [ Content ]

⸻

Почему две вкладки обязательны

Builder и content editing — это разные bounded contexts.

Если объединить их в один экран:

* execution editor перегружается текстами
* content manager получает лишнюю сложность
* message nodes превращаются в тяжёлые формы

⸻

Builder tab

Builder отвечает только за execution structure.

В зоне Builder:

* tree structure
* branch logic
* node order
* outputs
* conditions
* handler config

Builder не является местом массового редактирования текстов.

⸻

Content tab

Content tab отвечает за все текстовые ресурсы flow.

В зоне Content:

* message texts
* labels
* placeholders
* validation texts
* button captions
* retry texts
* translations

⸻

Node config хранит не текст напрямую, а content key

Пример:

{
"body_key": "welcome_message"
}

⸻

Content storage

{
"welcome_message": {
"uk": "Ласкаво просимо",
"en": "Welcome"
}
}

⸻

Content tab layout

Левая колонка

Список content keys:

welcome_message
retry_invalid_name
button_confirm
button_cancel

⸻

Центральная зона

Редактор выбранного ключа.

⸻

Правая зона

Языки:

UK
EN
PL

⸻

Пример

UK: Ласкаво просимо
EN: Welcome
PL: Witamy

⸻

Builder ↔ Content связь

Из Builder node можно перейти к content key.

Пример:

body_key = welcome_message
→ Open in Content

⸻

Какие node участвуют в Content

Content-aware nodes

* send_message
* input label
* validation text
* button text
* fallback message

⸻

Non-content nodes

* condition
* delay
* webhook
* handler

⸻

Архитектурное правило

Builder управляет execution contract.

Content управляет language/content layer.

Это разные слои.

⸻

14. Архитектурный итог

Builder = controlled tree editor.

Не graph editor.

Не form designer.

Не plugin shell.

⸻

Вывод

Builder должен оставаться platform-owned shell, внутри которого:

* core рендерит tree
* plugin живёт через schema
* solution расширяет config/preview
* runtime snapshot остаётся неизменным

---

## Связано с

- [[00-overview]] — schema renderer overview
- [[06-frontend-extension-boundary]] — ADR frontend расширений
- [[ROADMAP]] — дорожная карта builder
