# Нода `auth_request` (Core, P1)

> **Статус: РЕАЛИЗОВАНО (2026-06-05) — Core flag-setter.** По решению заказчика нода реализована как
> **Core-узел** (а не Feature-level), без bootstrap'а Feature-механики. Нода **challenge-типизирована**
> (`AuthMethod`): сейчас реализован тип `basic` — сравнение переменной с ожидаемым значением (расширенный набор
> операторов через общий `BranchOperator`: eq/neq/gt/gte/lt/lte/contains/in/empty/not_empty). При совпадении
> поднимается **каноничный флаг** `contact.is_authenticated` (новая boolean-колонка, как `language`, не в
> `attributes`). Запись флага идемпотентна (safe to retry).
> - **Не ветвитель.** Финальный дизайн: один выход `default` — нода ставит флаг и идёт дальше. Прежние выходы
>   `authenticated`/`failed` убраны (они и не имели портов на канве). Проверка auth — gate при старте flow
>   (`FlowAccessPolicy` + `flow_definitions.is_public`) и нода `branch` по `contact.is_authenticated`.
> - **UI:** override `AuthRequestConfig.vue` — `ConditionOperandPicker` (как у branch) + локализованные операторы
>   (`builder.operators.*`), без блока выходов.
> - Логика операнда и сравнения вынесена в общие `Handlers/Support/OperandResolver` + `OperatorComparator`
>   (переиспользуются `BranchNodeHandler` и `AuthRequestNodeHandler`).
> - Инфраструктура: миграция `add_is_authenticated_to_contacts_table`, `Contact` cast/fillable,
>   `ContactWriter::WRITABLE_COLUMNS += is_authenticated`. Регистрация в `FlowServiceProvider`.
> - Тесты: `tests/Feature/Domains/Flow/AuthRequestNodeHandlerTest.php` + canonical-column кейс в `ContactWriterTest`.
>
> **Отложено (будущие `AuthMethod`-типы):** `phone` (contact share), `sms_code`, `email_code` — добавляются как
> новые case'ы enum без изменения выходов. Полноценная Feature-механика (ActivationRegistry / TenantActivationRuntime)
> и фильтрация ноды по активации — **не вводились**; нода доступна на Core-уровне всегда. Спека ниже (Path 2 поверх
> `auth.*` примитивов) сохранена как ориентир для расширения.
>
> ## Две части механизма auth (2026-06-05)
> 1. **Установка флага** — нода `auth_request` (выше): проверяет признак и поднимает `contact.is_authenticated`.
> 2. **Проверка флага** — отдельной ноды не вводили: достаточно ноды `branch` с операндом `user_variable`
>    (storage=contact, name=`is_authenticated`). Для этого `is_authenticated` добавлен в whitelist каноничных колонок
>    `ScopedStateReader` — иначе branch читал бы из JSONB и получал null.
> 3. **Доступность flow (gate)** — флаг `flow_definitions.is_public` (mirror `flow_drafts.is_public`, default true).
>    `FlowAccessPolicy::canStart(definition, contact)` = `is_public || contact.is_authenticated` (fail-open для
>    null/legacy). Применяется в `FlowOrchestrator` на старте новой сессии: приватный flow для неаутентифицированного
>    контакта **не стартует** — падает в обычный fallback (как «нет доступного flow»). Resume существующей сессии,
>    subflow и persistent-button re-entry не гейтятся (это внутренняя композиция / уже начатый диалог).
>    Публикация переносит `is_public` из draft в definition (`PublishFlowService`). UI-тоггл уже был в Filament
>    `FlowFormSchema`. Тесты: `FlowAccessPolicyTest`, кейсы в `FlowOrchestratorTest` / `PublishFlowServiceTest` /
>    `ScopedStateReaderTest`.

**Слой:** Feature: AccessControl (node + auth handlers) · **Уровень:** Feature · **P3**

## Зачем
Аутентификация конечного пользователя внутри flow перед доступом к защищённому контенту: подтверждение телефона,
SMS/email-код и т.п. Ветвит flow по результату.

## Связь с Handler Registry (CLAUDE.md § Handler Registry)
AccessControl владеет namespace `auth.*`: `auth.phone_button`, `auth.code_sms`. CLAUDE.md фиксирует, что
`auth.phone_button` + `input(expected_type: contact)` уже дают полный сценарий аутентификации по телефону на
**примитивах**. Поэтому `auth_request` — это **высокоуровневая нода-оркестратор** поверх этих примитивов: один узел
вместо ручной связки phone_button → input → проверка → branch. Архитектурное решение для старта:
- **Path 1 (минимум):** не вводить отдельную ноду; документировать рецепт из примитивов (`auth.phone_button` +
  `input(contact)` + `branch`). Тогда `auth_request` остаётся в taxonomy как «зонтичный» сценарий, без своего handler'а.
- **Path 2 (полноценная нода):** `AuthRequestNodeHandler` инкапсулирует challenge + verify + отметку контакта.
Рекомендация: при реализации AccessControl выбрать осознанно. Ниже — спека для **Path 2**.

## configSchema (Path 2)
```php
Schema::make()
    ->required(['method'])
    ->section(
        Section::make('challenge', 'Authentication')->icon('shield-check')->fields([
            SelectField::make('method')->label('Method')
                ->options(['phone' => 'Phone (contact share)', 'sms_code' => 'SMS code', 'email_code' => 'Email code'])
                ->default('phone')->required(),
            NumberField::make('max_attempts')->label('Max attempts')->default(3)->min(1)->max(10),
            // sms/email-specific поля через visibleWhen(['method' => 'sms_code']) и т.п.
        ]),
    )
    ->toArray();
```

## Outputs (CLAUDE.md § Outputs)
- `authenticated` — успех; контакт помечен как аутентифицированный.
- `failed` — превышены попытки / отказ / таймаут.

## Runtime
- `AuthRequestNodeHandler` (`type()='auth_request'`, `version()=1`), регистрируется **только когда Feature
  AccessControl активирован** для tenant (`TenantActivationRuntime` фильтрует доступные node-handlers по owner_level/owner_id).
- Внутри — оркестрация `auth.*` handler'ов (phone_button / code_sms) + `input`.
- Отметка результата: контакт-level auth state (где хранить — решается вместе с AccessControl; кандидаты:
  `contacts.attributes` платформенной нодой запрещены для модулей, но AccessControl — Feature Core-уровня, может иметь
  свою таблицу `contact_auth` или canonical-поле; **решить при дизайне Feature**).
- **Идемпотентность / waiting-state:** auth — многошаговый (challenge → ждём ввод → verify). Сессия уходит в `waiting`,
  как `input`. Handler safe to retry, шаги фиксируются в `system.*`/feature-state.

## Инфраструктура (нужно создать) — БОЛЬШОЙ объём
1. **Bootstrap Feature-механики** (если ещё нет): `app/Features/`, `ActivatableInterface`, `ActivationRegistry`,
   `FeatureServiceProvider`, `tenant_activations` runtime (CLAUDE.md § Единая модель активации). Это предусловие, шире
   одной ноды.
2. `app/Features/AccessControl/` — провайдер, регистрация node-handler + `auth.*` handler'ов через `CoreRegistrar`.
3. Хранилище состояния аутентификации контакта (дизайн-решение).
4. SMS/email-код провайдеры (для `sms_code`/`email_code`) — внешние интеграции.

## Acceptance (Path 2)
- При активном AccessControl нода доступна в палитре; при неактивном — скрыта (`TenantActivationRuntime`).
- method=phone: связка challenge→input(contact)→verify; успех → `authenticated`, отказ/лимит → `failed`.
- Сессия корректно входит в `waiting` и возобновляется.
- Feature/unit тесты на оба выхода + лимит попыток + фильтрацию по активации.

## Риски / зависимости
- **Заблокировано bootstrap'ом Feature-механики** — это P3 и самый дальний пункт; не начинать раньше, чем появится
  первый Feature (RAG — тоже Feature, может проложить дорогу).
- Дизайн хранения auth-state контакта — отдельное решение, влияет на runtime resolution.
- Path 1 vs Path 2 — выбрать до кодинга, чтобы не плодить дублирующую механику поверх `auth.*` примитивов.

---

## Связано с

- [[README]] — nodes README
- [[00-overview]] — auth specs overview
- [[03-branch]] — gate проверки через branch
- [[05-contacts]] — canonical contact.is_authenticated поле
