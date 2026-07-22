# CLAUDE.md - FAPost Core Agent Rules

Контекст для Claude Code и других агентов. Читать перед началом задачи.

Этот файл не является roadmap и не фиксирует статусы реализации. Здесь живут устойчивые правила кода, архитектурные
ограничения и навигация по рабочей документации.

## Project Context

FAPost Core - ядро платформы для диалоговых ассистентов и flow automation. Этот репозиторий содержит Core без SaaS shell
и без нишевых Solution-пакетов.

Рабочая документация:

- `drafts/CURRENT_TASK.md` - текущий операционный фокус. Оставлять как главный краткосрочный ориентир.
- `docs/INDEX.md` - основной индекс документации.
- `docs/platform/TASKS.md` - трекер статусов реализации.
- `docs/platform/ROADMAP.md` - milestone'ы и зависимости.
- `docs/platform/PROJECT.md` - краткий проектный контекст.
- `docs/platform/current-state.md` - фактическое состояние репозитория.
- `docs/developers/index.html` - HTML-портал для будущих разработчиков Features, Solutions и Plugins.

Не дублировать в этом файле чекбоксы, планы, списки будущих таблиц или продуктовые обещания. Если статус изменился,
обновлять `docs/platform/TASKS.md`, `docs/platform/ROADMAP.md` и/или `docs/platform/current-state.md`.

## Current Stack

- PHP 8.4
- Laravel 12
- PostgreSQL с landlord / tenant connections
- Redis для cache, queues, locks и hot-path registry
- Horizon queues
- Octane ограниченно, только для webhook ingress, если включен окружением
- Filament admin
- Inertia + Vue builder
- PHPUnit 12
- PHPat/PHPStan architecture checks

## Directory Boundaries

Основной код:

```text
app/
  Domains/          Technical bounded contexts: Tenancy, Flow, Messaging, Contact, Assistant, Channels, Media, Staff.
  Filament/         Admin UI.
  Http/             Controllers, middleware, builder endpoints.
  Jobs/             Cross-domain orchestration jobs.
  Providers/        Laravel service providers.

database/migrations/
  landlord/         Platform / landlord schema.
  tenant/           Tenant schema.

packages/
  fapost-foundation Public contracts and DTOs for Core/Solutions/Plugins.
  fapost-support    Shared primitives.

resources/js/builder/
  Vue flow builder.
```

Не создавать новые base folders без явного решения. В частности, `app/Features` и Solution folders не считать
существующими, пока они реально не добавлены в код.

## Laravel And PHP Rules

- Следовать существующим паттернам соседних файлов.
- Каждый `.php` файл начинается с `declare(strict_types=1);`.
- `final class` по умолчанию.
- Constructor property promotion и explicit return types.
- Enum cases в TitleCase.
- PHPDoc использовать для смысла, array shapes и дженериков; inline comments - только для сложной логики.
- Для новых Laravel artifacts предпочитать `php artisan make:* --no-interaction`, если это уместно.
- Не добавлять зависимости без согласования.
- После изменения PHP запускать `vendor/bin/pint --dirty --format agent`.
- Каждое изменение кода покрывать минимальным релевантным тестом и запускать этот тест.
- Документационные файлы создавать только когда пользователь явно просит.

## Tenant-Aware Execution

Tenant - базовая координата runtime. Core-код в runtime должен fail fast, если tenant context обязателен, но не установлен.

Запрещено:

- SaaS-specific branching внутри Core runtime.
- `if (isSaas())` и похожие проверки.
- fallback на "default tenant" вместо явного tenant context.
- прямой landlord lookup из доменов вне `Tenancy`.

Разрешенный landlord access pattern: доменам нужен контракт из `Tenancy/Contracts`; прямой `DB::connection('landlord')`
остается внутри Tenancy infrastructure.

## Migration Isolation

Миграция - DDL-операция. В `up()` / `down()` нельзя завязываться на runtime state.

Запрещено:

- `app()`, `config()`, `env()` для runtime решений.
- `TenantContext::get()` и tenant-aware сервисы.
- ветки на feature/module activation.
- seed-данные, зависящие от runtime состояния.
- `DB::table()` поверх таблиц другого модуля из миграции модуля.

Важно: PHPat-правила должны соответствовать тексту этого раздела. Они выполняются через `phpstan.neon`; default
PHPUnit run покрывает их через `tests/Unit/Architecture/MigrationTest.php`.

## Octane Scope

Octane допустим только для stateless webhook ingress. Admin, builder, Filament и stateful user flows не должны
предполагать long-lived request runtime.

Для Octane-safe кода:

- не держать request, config repository, tenant context или current assistant в singleton constructor;
- mutable request/job state держать в `scoped` bindings;
- tenant switch выполнять через `TenantSwitcher::runForTenant()` с restore в `finally`;
- не писать в static properties между запросами.

## ID Strategy

- Tenant-schema primary keys: ULID, сохраненный в PostgreSQL `uuid`.
- Использовать `App\Domains\Shared\Concerns\HasUlidPrimaryKey`, если модель следует этой стратегии.
- Миграции: `$table->uuid('id')->primary()` без database default.
- FK: `foreignUuid(...)->constrained()->cascadeOnDelete()` или локальный эквивалент по существующему стилю.
- Не менять специальные публичные идентификаторы вроде webhook public hash без отдельного решения.

## Dependency Direction

`packages/fapost-foundation` и `packages/fapost-support` не зависят от Core.

Запрещено:

- `use App\...` внутри foundation/support.
- ссылки из foundation/support на конкретные доменные классы Core.
- перенос бизнес-логики Core в support.

Если контракт нужен внешним Solution/Plugin - он принадлежит foundation. Если это чистый переиспользуемый примитив без
Core-зависимостей - support. Если используется в одном домене и несет доменную семантику - остается в Core.

## Domain Code Rules

- Controllers и Jobs только оркестрируют; бизнес-логика живет в services/domain classes.
- В доменных сервисах не использовать `app()`, `resolve()`, глобальные Laravel helpers как скрытые зависимости.
- Repositories/ports использовать там, где домен пересекает persistence boundary или другой bounded context.
- Facades допустимы в infrastructure layer: providers, jobs, controllers, migrations, framework adapters.
- Eloquent models живут в `Domains/{Domain}/Models`.
- Relations остаются на моделях, когда нужны Eloquent query capabilities.

## Flow Engine Rules

- Handler резолвится по `(type, version)` из in-memory registry.
- Handler должен быть graph-unaware: возвращает `sourceHandle`, а не следующий node id.
- Breaking change в node contract требует новую версию handler; старые flow definitions продолжают работать.
- Flow session выполняет snapshot своего `flow_definition_id` до завершения.
- Handler обязан быть safe to retry; внешние side effects должны иметь idempotency marker или эквивалентную защиту.
- State key должен быть namespaced: `system.*`, `flow.*`, `rag.*`, `module.*`.
- `module.*` read-only для Flow Engine и резолвится через `DataAccessorInterface`.
- `system.*` writes разрешать только явно whitelisted runtime handlers.
- Не возвращать legacy `effects[]`; использовать writer/port из execution context.

## Messaging And Queues

Очереди не смешивать по назначению:

- `flow.execution` - обработка входящих и execution pipeline.
- `messaging.transactional` - ответы в активном диалоге.
- `messaging.broadcast` - низкоприоритетные рассылки/fan-out.
- `messaging.system` - служебные уведомления.
- `scheduled.triggers` - scheduled/event trigger fan-out.
- `sync.external` - внешние синхронизации.

Provider rate limit и backpressure должны быть превентивными, а не только реакцией на ошибку провайдера.

## Multilingual Rules

Разделять два языковых слоя:

- Admin UI language - Laravel lang files, Filament/backend validation/staff UI.
- Content language - runtime сообщения ассистента конечному пользователю.

Runtime language resolution проходит через `LanguageResolverInterface` / content translator chain. Не вставлять
пользовательские bot-facing литералы напрямую в handlers/senders. Такие строки должны быть системными translation keys
или flow content.

Button/select `value` language-agnostic и не переводится; переводится только label/content.

## Frontend Builder Rules

- Builder должен опираться на registry/config schema и существующие overrides.
- Для core node-specific UI использовать bespoke override только когда schema-driven renderer недостаточен.
- Plugin без rebuild frontend не может поставлять Vue components; расширять schema renderer в Core.
- Solution/vendor components допустимы только через согласованный Vite glob/publish contract.
- Не добавлять маркетинговые landing surfaces в builder/admin вместо рабочей функциональности.

## Documentation Discipline

- `docs/platform/current-state.md` описывает факт, а не цель.
- `docs/platform/TASKS.md` может использовать `done / partial / pending`, если одна строка содержит и каркас, и продуктовую
  фичу.
- `docs/platform/ROADMAP.md` описывает будущие milestone'ы и зависимости.
- `docs/developers/` - HTML developer portal, не внутренняя Obsidian/markdown-база.
- Active source-of-truth documentation must be written in English. Archive files may keep their original language until
  deleted or rewritten.
- `drafts/` должен содержать только `CURRENT_TASK.md`.
- `CLAUDE.md` не должен утверждать наличие таблиц, моделей, jobs или UI, если это не архитектурное правило и не
  подтверждено кодом.
- При обнаружении расхождения между кодом и документацией сначала уточнить: это stale documentation, partial feature
  или false positive в коде.

## Verification Notes

- `composer test` / `php artisan test` запускает PHPUnit suites из `phpunit.xml`.
- `composer run test:arch` запускает PHPat architecture rules через PHPStan.
- PHPat rules лежат в `tests/Architecture`; низкоуровневая команда: `vendor/bin/phpstan analyse --configuration phpstan.neon`.
- Default PHPUnit run покрывает PHPat через `tests/Unit/Architecture/MigrationTest.php`, который запускает phpstan.
- Не запускать `php artisan test tests/Architecture` как проверку PHPat: эти классы не являются PHPUnit `TestCase`.
