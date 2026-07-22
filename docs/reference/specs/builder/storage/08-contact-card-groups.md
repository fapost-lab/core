# 08 · Contact card — отображение групп секциями

**Зависит от:** 05 (резолвер пишет в `contact.attributes.{group}.{name}` структуру)
**Блокирует:** —
**Слой:** Filament admin (PHP)

## Цель

Соответствует п.7 спеки. В Filament admin → Contacts → конкретный контакт показывать `attributes` группами:
- Корневые поля (без группы) — секция «Profile»
- Группы — отдельные коллапсируемые секции с пометкой «(N fields)»
- Платформенные данные (`meta.*`) — отдельная секция «From platform»
- Temporary поля (session) — НЕ показываются (они в session, не в Contact)

## Текущее состояние

Полностью отсутствует — Contact resource пока не реализован в Filament (см. CLAUDE.local.md backlog: V1.x backlog mentions FlowSession Inspector, но не Contact viewer).

Эта задача может потребовать сначала создать `ContactResource` в admin panel — оценить scope в начале реализации. Если окажется большой — выделить под-задачу `08a-contact-resource-foundation.md`.

## UI макет

```
📇 Иван Петров
  Channel:    Telegram
  Channel ID: 123456789
  Username:   @ivanov

  ─── Profile ───
  name:     Иван
  phone:    +7 999 123 4567
  email:    ivan@example.com

  ─── ▼ survey_q1_2026 (3 fields) ───
  overall_score:    8
  recommendation:   yes
  comment:          Отлично

  ─── ▼ address (2 fields) ───
  city:             Москва
  street:           Ленина 1

  ─── from platform ───
  username (Telegram): @ivanov
  channel_user_id:     123456789
```

Группы collapsible, по умолчанию развёрнуты при ≤ 3 групп; свёрнуты иначе.

## Filament implementation

`ContactResource::view` использует Filament Infolist:

```php
Infolist::make()
    ->schema([
        Section::make('Profile')
            ->schema(self::rootAttributeFields($contact)),

        ...self::groupSections($contact),

        Section::make('from platform')
            ->collapsible()
            ->collapsed()
            ->schema(self::metaFields($contact)),
    ]);
```

Helper `groupSections()` итерирует по `contact.attributes`, собирает все ключи второго уровня в группы, рендерит каждую как `Section::make($group)->collapsible()` с `TextEntry` per поле.

## Field rendering

`TextEntry::make("attributes.{$group}.{$name}")` для каждого поля. Тип отображения по `Variable::type` (если разработана задача 05) — number с форматом, date через Carbon, boolean через `Yes/No`, etc.

Если `Variable::type` ещё не доступно (задача 05 не сделана для всех handlers) — fallback: тип определяется по PHP `gettype()`.

## Что НЕ показываем

- `flow_sessions.state` — это runtime данные, не профиль контакта.
- Reserved system keys (`id`, `tenant_id`, `external_id`, …) — уже отдельные колонки контакта.
- Группа `meta` идёт в отдельную секцию «from platform» — не смешиваем с user-контентом.

## Файлы

- `app/Filament/Resources/Contacts/ContactResource.php` — может потребовать создания (зависит от текущего состояния)
- `app/Filament/Resources/Contacts/Schemas/ContactInfolistSchema.php` — рендер групп
- `app/Filament/Resources/Contacts/Pages/ViewContact.php`
- `lang/{en,ru,uk}/contact.php` — переводы заголовков секций

## Acceptance

- Контакт с `attributes = { name: 'X', survey_q1: { score: 8 } }` отображается в двух секциях: Profile (name) и survey_q1 (score).
- Все группы collapsible независимо.
- При пустых attributes показывается только секция «from platform» если есть meta.
- Sensitive поля (`meta.username`, etc.) не редактируются прямо из этой view (read-only).

## Out of scope

- Edit attributes inline (требует отдельного UX про permissions)
- Bulk export по группе (V1.x backlog item — см. п.4 спеки про аналитику)

---

## Связано с

- [[05-contacts]] — Contact Domain
- [[00-overview]] — overview storage
- Drag-reorder секций (V1.x)
