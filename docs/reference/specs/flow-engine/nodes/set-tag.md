# Нода `set_tag` (Core, P1)

> **Статус: РЕАЛИЗОВАНО (2026-06-05).** Миграция `contact_tags`, модель `ContactTag`, контракт
> `ContactTagRepositoryInterface` + Eloquent-реализация (биндинг в `ContactServiceProvider`), хендлер
> `SetTagNodeHandler` (`type=set_tag`, `v1`, generic renderer), регистрация в `FlowServiceProvider`.
> add/remove/toggle идемпотентны (unique `(contact_id, tag)`), `tagged_by = flow_session_id`, шаблонные теги
> резолвятся, пустые/whitespace отбрасываются. Тесты: `tests/Feature/Domains/Flow/SetTagNodeHandlerTest.php`.

**Слой:** Domain Contact (таблица/модель/сервис) + Domain Flow (NodeHandler) · **Уровень:** Core · **P1**

## Зачем
Динамическая разметка контактов прямо из flow: пометить «оплатил», «лид», «vip» и т.п. Теги — один из трёх концептов
сегментации (Groups / **Tags** / Segments), используются Broadcasting'ом при выборке получателей через
`ContactSegmentResolver`.

## configSchema (generic renderer — override НЕ нужен)
```php
Schema::make()
    ->section(
        Section::make('action', 'Tagging')->icon('tag')->fields([
            SelectField::make('action')->label('Action')
                ->options(['add' => 'Add', 'remove' => 'Remove', 'toggle' => 'Toggle'])
                ->default('add')->required(),
            ArrayField::make('tags')->label('Tags')
                ->help('Language-agnostic labels. Not translated.'),
        ]),
    )
    ->toArray();
```
- `action`: `add` | `remove` | `toggle`.
- `tags`: `array<string>` — теги **language-agnostic**, не переводятся (как `value` у кнопок).
- Шаблонизация значений тегов (`{{flow.x}}`) допустима — резолвится перед записью.

## Outputs
- `default` — единственный выход. Тегирование не ветвит.

## Runtime
- Хендлер `SetTagNodeHandler implements NodeHandlerInterface` (`type()='set_tag'`, `version()=1`).
- Пишет в `contact_tags` (`contact_id`, `tag`, `tagged_by`, `tagged_at`) через репозиторий (не Eloquent в сервисе напрямую — см. CLAUDE.md § Code conventions).
- `tagged_by` = `flow_session_id` текущей сессии.
- **Идемпотентность (CLAUDE.md § Concurrency — handler safe to retry):**
  - `add` — upsert по уникальному `(contact_id, tag)`; повторный прогон не дублирует.
  - `remove` — delete по `(contact_id, tag)`; повтор безвреден.
  - `toggle` — резолвится по текущему наличию; для retry-safety читать-и-писать под уже взятым session lock.

## Инфраструктура (нужно создать)
1. Миграция `tenant/..._create_contact_tags_table` — `contact_id` (foreignUuid, cascade), `tag` (string), `tagged_by`
   (uuid nullable — `flow_session_id` | `staff_user_id`), `tagged_at`. Unique `(contact_id, tag)`. PK — ULID per ADR-03.
2. Модель `App\Domains\Contact\Models\ContactTag` (BaseModel, HasUlidPrimaryKey).
3. Контракт + репозиторий `ContactTagRepositoryInterface` → Eloquent impl, биндинг в `ContactServiceProvider` (или DomainServiceProvider).
4. Регистрация хендлера в `FlowServiceProvider::register()` рядом с остальными.

## Acceptance
- add/remove/toggle меняют `contact_tags` корректно; повторный прогон ноды не плодит дубли.
- `tagged_by` = текущий `flow_session_id`.
- Нода рендерится generic-рендерером (в `OVERRIDES` не добавляется).
- Unit + feature тесты на три action'а + idempotency + retry.

## Риски
- Согласовать формат `tagged_by` (полиморфный источник flow vs staff) с уже задокументированной схемой в CLAUDE.md.
- Шаблонные теги: пустые/whitespace после резолва — отбрасывать.

---

## Связано с

- [[README]] — nodes README
- [[05-contacts]] — Contact Domain, contact_tags таблица
- [[03-branch]] — фильтрация по тегам в условиях
