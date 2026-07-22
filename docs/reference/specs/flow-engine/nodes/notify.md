# Нода `notify_staff` → `notify` (Core, P1)

> **⚠️ Переименована в `notify` (2026-06-05).** Нода объединена в один тип `notify` с двумя режимами (`NotifyConfig.vue`):
> **Staff** (этот файл) и **Contacts** (рассылка контактам через ассистент по тегу/«все», content-язык, доставка
> `messaging.broadcast` через `SendContactNotificationJob`). Выбор сотрудников/ассистента/тегов — дропдауны с поиском
> (`SearchSelect.vue`, эндпоинты `/builder/staff|assistants|tags`). Ручное назначение тегов контакту — в Filament
> `ContactResource` (action «Manage tags», `TagsInput`). Ниже — исходная спека staff-режима.
>
> **Статус contacts-режима: РЕАЛИЗОВАНО (2026-06-05).** Доставка контактам работает (раньше была UI-only —
> `executeContacts()` лишь писал `delivered=false` в metadata).
> - `NotifyNodeHandler::executeContacts()` валидирует `assistant_id` + непустой `message`, рендерит шаблон против
>   текущей сессии (`{{flow.*}}`, рекурсивно по локализованной map), ставит state-маркер
>   `system.contacts_notified.{nodeId}` (идемпотентность handler-а) и диспатчит `SendContactNotificationJob`.
> - `App\Domains\Contact\Jobs\SendContactNotificationJob` (очередь `messaging.broadcast`): Redis-guard на
>   `(tenant, session, node)`, восстановление tenant-контекста, резолв получателей — **tag** (union
>   `ContactTagRepository::contactIdsWithTag` ∩ активный `ChannelContact` ассистента) либо **all** (все контакты с
>   активной привязкой к каналу ассистента); один `BroadcastSendJob`(`OutboundMessage`) на получателя. Контент-язык
>   per-recipient через `ContentTranslatorInterface::resolveField` (`contact.language → assistant.default_language →
>   tenant fallback`) — в отличие от staff (Admin-UI язык).
> - Платформенное ограничение соблюдено: можно писать только контакту с уже существующим `channel_contact` для
>   ассистента (нельзя инициировать первый контакт в TG/WA); недоставляемые tag-контакты пропускаются и логируются.
> - Идемпотентность реальной отправки — per-message ключ `{session}:{node}:notify:{contactId}` в `MessageSender`;
>   rate-limit per `(channel, chat)` там же. Предупреждающий баннер в `NotifyConfig.vue` убран.
> - Тесты: `tests/Feature/Domains/Flow/NotifyNodeHandlerTest.php` (contacts: диспатч + маркер, идемпотентность,
>   no-assistant, empty-message), `tests/Feature/Domains/Contact/SendContactNotificationJobTest.php` (tag только до
>   достижимых, all, идемпотентность ретрая, per-recipient язык).
> - ⏳ Осталось: backpressure по длине transactional-очереди в `BroadcastSendJob` (стаб не реализует проверку —
>   это пре-существующий пробел шире данной ноды; полагаемся на rate-limit `MessageSender` + low-priority очередь).
>
> **Статус staff-режима: РЕАЛИЗОВАНО (2026-06-05).** Реализовано с расширением дизайна (по решению заказчика): **выбор канала**
> + **гибкая выборка получателей**.
> - Каналы за `StaffNotifierInterface` + `StaffNotifierRegistry`: `in_app` (Filament DB-нотификации, D1) и `email`
>   (D3, Mail-фасад + `StaffNotificationMail`). В конфиге ноды поле `channel`: `in_app` | `email` | `all`
>   (`all` → все зарегистрированные транспорты). Новый транспорт (мессенджер-бот) добавляется одной строкой в
>   `StaffServiceProvider`, ноду менять не нужно.
> - Получатели через `StaffRecipientResolver`: `assistant` (все назначенные через `user_assistants`) | `role` |
>   `users`. Только активные (`is_active` + `status=active`).
> - Доставка: `SendStaffNotificationJob` на очередь `messaging.system`, восстанавливает tenant-контекст,
>   резолвит получателей из `assistant_id` сессии. Идемпотентность двумя слоями: state-маркер
>   `system.staff_notified.{nodeId}` (handler) + Redis-guard на `(session, node)` (job). Падающий канал
>   логируется и не валит остальные.
> - Инфраструктура: добавлена tenant-миграция `notifications` (uuid morphs). Тексты — Admin-UI язык (не через
>   `LanguageResolver`). Тесты: `tests/Feature/Domains/Staff/StaffNotificationTest.php`,
>   `tests/Feature/Domains/Flow/NotifyStaffNodeHandlerTest.php`.
>
> Открыто на будущее: «staff ↔ messenger identity» (D2) и адресация staff по тегу — пока нет модели staff-тегов.

**Слой:** Domain Flow (NodeHandler) + Staff/Messaging (доставка) · **Уровень:** Core · **P1**

## Зачем
Эскалация диалога живому сотруднику из flow: «нужен оператор», «пришла заявка», «бот не справился». Уведомляет
**staff** (Users в tenant-схеме), не конечного пользователя.

## Адресация (кого уведомляем)
Staff = `App\Domains\Staff\Models\User`, привязка к ассистенту через `user_assistants`. Target:
- `assistant` — все staff, назначенные на текущего ассистента (через `user_assistants`).
- `role` — staff с конкретной ролью (RoleEnum, см. Auth unification).
- `users` — явный список user_id.

По умолчанию — `assistant` (все назначенные).

## configSchema (generic renderer — override НЕ нужен)
```php
Schema::make()
    ->section(
        Section::make('target', 'Recipients')->icon('users')->fields([
            SelectField::make('target')->label('Notify')
                ->options(['assistant' => 'All assistant staff', 'role' => 'By role', 'users' => 'Specific users'])
                ->default('assistant')->required(),
            SelectField::make('role')->label('Role')
                ->options([/* RoleEnum cases */])
                ->visibleWhen(['target' => 'role']),
            ArrayField::make('user_ids')->label('User IDs')
                ->visibleWhen(['target' => 'users']),
        ]),
    )
    ->section(
        Section::make('message', 'Message')->icon('chat-bubble-left-ellipsis')->fields([
            TextareaField::make('message')->label('Notification text')->required()
                ->help('Admin-UI language. Supports {{flow.*}} / {{system.*}} templates.'),
        ]),
    )
    ->toArray();
```

> **Multilingual nuance (CLAUDE.md § Multilingual):** текст staff-уведомления — это **Admin UI language**, не content
> language. Не прогонять через `LanguageResolver`/`CachedContentTranslator` (те — для сообщений конечному пользователю).
> Локаль получателя — из настроек staff-пользователя.

## Outputs
- `default` — единственный выход (уведомление — fire-and-forward, не ждёт ответа staff).

## Runtime
- `NotifyStaffNodeHandler` (`type()='notify_staff'`, `version()=1`).
- Резолвит получателей → диспатчит доставку в очередь **`messaging.system`** (CLAUDE.md § Очереди — служебные
  уведомления платформы), не в transactional.
- Шаблон `message` резолвится из state перед отправкой.
- **Идемпотентность:** ключ доставки на `(flow_session_id, node_id)` — чтобы retry job'а не слал дубликат.

## Канал доставки — ⚠️ DECISION POINT
Чем именно staff получает уведомление — открыто, выбрать при старте:
- **D1 (рекомендуется для Core baseline):** Filament database notification (in-app, Filament v5) — staff видит в
  админке. Минимум внешних зависимостей, работает для self-hosted из коробки.
- **D2:** доставка в мессенджер staff (если у сотрудника привязан Telegram/WhatsApp контакт) — мощнее, но требует
  модели «staff ↔ messenger identity», которой пока нет.
- **D3:** email.
Рекомендация: начать с **D1**, остальные транспорты — отдельной итерацией за интерфейсом `StaffNotifierInterface`.

## Инфраструктура (нужно создать)
1. `StaffNotifierInterface` + Core-реализация (D1: Filament notification).
2. Job `SendStaffNotificationJob` в очередь `messaging.system`.
3. Регистрация хендлера в `FlowServiceProvider`.
4. (D1) — использовать встроенные Filament notifications; таблица `notifications` стандартная Laravel.

## Acceptance
- target=assistant → уведомление получают все staff из `user_assistants` для текущего ассистента.
- role / users — корректная выборка.
- Текст резолвит `{{...}}` из state; локаль — admin UI staff-пользователя.
- Уведомление идёт в `messaging.system`; retry job'а не плодит дубли.
- Generic renderer (в `OVERRIDES` не добавляется). Feature/unit тесты на адресацию + idempotency.

## Риски
- **Decision point канала доставки** — не начинать без выбора D1/D2/D3.
- Пустой список получателей (никто не назначен на ассистента) — не падать, лог + no-op (опц. отдельный output `no_staff` — но это усложняет; в V1 не вводить).
- Не путать локали: staff-уведомление — admin locale, не content.

---

## Связано с

- [[README]] — nodes README
- [[10-message-pipeline]] — message pipeline для отправки
- [[15-multilingual]] — языковой резолвинг для notify
- [[03-staff-users]] — Staff пользователи как получатели
