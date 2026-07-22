# Task 08 — Webhook Message Pipeline

> Архив Notion. Актуальная документация: [[10-message-pipeline]]


Depends on: 06, 07
Domain: Assistant
Phase: 1 — Core Platform
Sprint: 3
Status: Готово
Task №: 8

## Цель

Реализовать входящий webhook transport pipeline: от HTTP-запроса до dispatch нормализованного DTO в async очередь. Задача заканчивается на transport boundary — flow execution, trigger resolution и contact business logic не входят в scope.

---

## Принципы

- Adapter — только transport. Не знает о тенантах, контактах, Redis.
- Controller всегда возвращает 200 — даже при invalid signature или missing registry.
- Verify signature **до** idempotency check — иначе forged payload отравит кеш.
- Idempotency key: `processed:{channel_id}:{update_id}` — не просто `update_id` (коллизии между ботами).
- `IncomingMessage` содержит `PlatformEnum`, не строку — нормализация завершается до domain pipeline.
- `WebhookRegistryEntry` DTO — controller не работает с сырыми массивами из Redis.
- Адаптеры резолвятся через контейнер (`$app->make()`), не через `new`.
- Job живёт в `app/Domains/Webhook/Jobs/`, не в `app/Jobs/`.
- `TenantSwitcher::runForTenant()` — переключение контекста в Job.
- `ContactServiceInterface::findOrCreateChannelContact()` — вызов из Job без дублирования логики Task 07.

---

## Структура файлов

```
app/
└── Domains/
    └── Webhook/
        ├── Contracts/
        │   └── ChannelAdapterInterface.php
        ├── DTOs/
        │   ├── IncomingMessage.php
        │   └── WebhookRegistryEntry.php
        ├── Adapters/
        │   ├── TelegramChannelAdapter.php
        │   └── WhatsAppChannelAdapter.php
        ├── Services/
        │   └── ChannelAdapterResolver.php
        ├── Exceptions/
        │   ├── InvalidSignatureException.php
        │   ├── AdapterNotFoundException.php
        │   └── WebhookRegistryException.php
        ├── Jobs/
        │   └── IncomingMessageJob.php
        ├── Http/
        │   └── WebhookController.php
        └── Providers/
            └── WebhookServiceProvider.php
```

---

## Реализация

### ChannelAdapterInterface

```php
interface ChannelAdapterInterface
{
    public function verifySignature(Request $request, string $secretToken): void;
    public function parse(Request $request): IncomingMessage;
}
```

### WebhookRegistryEntry

Нормализует Redis payload немедленно. Поля: `tenantId`, `channelId`, `platform` (PlatformEnum), `secretToken`.

Фабричный метод `fromRedis(string $json): self`.

### IncomingMessage

Поля: `updateId`, `externalChatId`, `externalUserId`, `text`, `platform` (PlatformEnum), `messageType`, `timestamp`, `meta[]`.

### TelegramChannelAdapter

Проверяет заголовок `x-telegram-bot-api-secret-token` через `hash_equals`.

Критический порядок извлечения sender:

```php
$from = $payload['callback_query']['from']
    ?? $payload['message']['from']
    ?? [];
```

Причина: sender в callback_query отличается от sender в message — неправильный порядок даёт неверный contact binding.

### WhatsAppChannelAdapter

Stub. Реализует интерфейс. Бросает `LogicException`.

### ChannelAdapterResolver

Принимает `Container` + `array $adapterMap [platform => class-string]`. Резолвит через `$container->make($class)`.

### WebhookController — порядок выполнения

1. Resolve registry из Redis → `WebhookRegistryEntry`
2. Resolve adapter по `$entry->platform`
3. Verify signature
4. Extract `update_id` из raw payload
5. Idempotency check (`Redis::set("processed:{channelId}:{updateId}", NX, EX 86400)`)
6. Parse DTO
7. Dispatch `IncomingMessageJob` → queue `flow.execution`
8. Всегда return `['ok' => true]` 200

Исключения `InvalidSignatureException`, `AdapterNotFoundException`, `WebhookRegistryException` ловит controller, не глобальный handler. `report($e)` + return 200.

### IncomingMessageJob

Принимает: `IncomingMessage`, `string $tenantId`, `string $channelId`.

```php
public function handle(
    TenantRepositoryInterface $tenantRepository,
    TenantSwitcher $switcher,
    ContactServiceInterface $contactService,
): void {
    $tenant = $tenantRepository->getById($this->tenantId);
    $switcher->runForTenant($tenant, function () use ($contactService): void {
        $contactService->findOrCreateChannelContact(
            platform: $this->message->platform,
            externalChatId: $this->message->externalChatId,
            externalUserId: $this->message->externalUserId,
            channelId: $this->channelId,
            meta: $this->message->meta,
        );
        // Handoff → Task 14 (TriggerResolver)
    });
}
```

`tries = 3`, `backoff = 5`. `TenantNotFoundException` не ловим — данные сломаны, retry не поможет.

### Route

```php
// app/Domains/Webhook/routes/webhook.php
Route::post('/webhooks/{platform}/{hash}', WebhookController::class)
    ->name('webhook.incoming');
```

Путь `/webhooks/*` зарезервирован под Octane (ADR-01).

### WebhookServiceProvider

Регистрирует `ChannelAdapterResolver` как singleton с adapterMap. Загружает routes через `loadRoutesFrom`.

---

## Что НЕ делать

- Не читать/писать Redis webhook registry — только чтение. Запись — Task 06.
- Не дублировать contact business rules — только вызов `ContactServiceInterface`.
- Не передавать raw payload в Job — транспорт умирает внутри адаптера.
- Не ловить исключения в глобальном handler для этого endpoint — политика специфична для transport.
- Не использовать `new TelegramChannelAdapter()` — только через контейнер.
- Не хранить Job в `app/Jobs/` — только `app/Domains/Webhook/Jobs/`.
- Не возвращать non-200 при ошибках signature/registry — Telegram будет ретраить.

---

## Полный код файлов

### app/Domains/Webhook/DTOs/WebhookRegistryEntry.php

```php
<?php

declare(strict_types=1);

namespace App\Domains\Webhook\DTOs;

use App\Domains\Shared\Enums\PlatformEnum;
use Spatie\LaravelData\Data;

final class WebhookRegistryEntry extends Data
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $channelId,
        public readonly PlatformEnum $platform,
        public readonly string $secretToken,
    ) {}

    public static function fromRedis(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return new self(
            tenantId: $data['tenant_id'],
            channelId: $data['channel_id'],
            platform: PlatformEnum::from($data['platform']),
            secretToken: $data['secret_token'],
        );
    }
}
```

### app/Domains/Webhook/DTOs/IncomingMessage.php

```php
<?php

declare(strict_types=1);

namespace App\Domains\Webhook\DTOs;

use App\Domains\Shared\Enums\PlatformEnum;
use Spatie\LaravelData\Data;

final class IncomingMessage extends Data
{
    public function __construct(
        public readonly string $updateId,
        public readonly string $externalChatId,
        public readonly string $externalUserId,
        public readonly ?string $text,
        public readonly PlatformEnum $platform,
        public readonly string $messageType,
        public readonly int $timestamp,
        public readonly array $meta = [],
    ) {}
}
```

### app/Domains/Webhook/Contracts/ChannelAdapterInterface.php

```php
<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Contracts;

use App\Domains\Webhook\DTOs\IncomingMessage;
use Illuminate\Http\Request;

interface ChannelAdapterInterface
{
    public function verifySignature(Request $request, string $secretToken): void;

    public function parse(Request $request): IncomingMessage;
}
```

### app/Domains/Webhook/Adapters/TelegramChannelAdapter.php

```php
<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Adapters;

use App\Domains\Shared\Enums\PlatformEnum;
use App\Domains\Webhook\Contracts\ChannelAdapterInterface;
use App\Domains\Webhook\DTOs\IncomingMessage;
use App\Domains\Webhook\Exceptions\InvalidSignatureException;
use Illuminate\Http\Request;

final class TelegramChannelAdapter implements ChannelAdapterInterface
{
    private const HEADER = 'x-telegram-bot-api-secret-token';

    public function verifySignature(Request $request, string $secretToken): void
    {
        $provided = $request->header(self::HEADER, '');

        if (!hash_equals($secretToken, $provided)) {
            throw new InvalidSignatureException('Invalid Telegram secret token.');
        }
    }

    public function parse(Request $request): IncomingMessage
    {
        $payload = $request->json()->all();

        // Sender из callback_query имеет приоритет над sender из message
        $from = $payload['callback_query']['from']
            ?? $payload['message']['from']
            ?? [];

        $message = $payload['message']
            ?? $payload['callback_query']['message']
            ?? [];

        $callbackData = $payload['callback_query']['data'] ?? null;

        return new IncomingMessage(
            updateId: (string) $payload['update_id'],
            externalChatId: (string) ($message['chat']['id'] ?? ''),
            externalUserId: (string) ($from['id'] ?? ''),
            text: $callbackData ?? $message['text'] ?? null,
            platform: PlatformEnum::Telegram,
            messageType: $this->resolveMessageType($payload),
            timestamp: $message['date'] ?? time(),
            meta: array_filter([
                'username'   => $from['username'] ?? null,
                'first_name' => $from['first_name'] ?? null,
                'last_name'  => $from['last_name'] ?? null,
            ]),
        );
    }

    private function resolveMessageType(array $payload): string
    {
        return match (true) {
            isset($payload['callback_query'])      => 'callback_query',
            isset($payload['message']['photo'])    => 'photo',
            isset($payload['message']['document']) => 'document',
            isset($payload['message']['voice'])    => 'voice',
            isset($payload['message']['sticker'])  => 'sticker',
            isset($payload['message']['text'])     => 'text',
            default                                => 'unknown',
        };
    }
}
```

### app/Domains/Webhook/Adapters/WhatsAppChannelAdapter.php

```php
<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Adapters;

use App\Domains\Webhook\Contracts\ChannelAdapterInterface;
use App\Domains\Webhook\DTOs\IncomingMessage;
use Illuminate\Http\Request;

final class WhatsAppChannelAdapter implements ChannelAdapterInterface
{
    public function verifySignature(Request $request, string $secretToken): void
    {
        throw new \LogicException('WhatsApp adapter is not implemented yet.');
    }

    public function parse(Request $request): IncomingMessage
    {
        throw new \LogicException('WhatsApp adapter is not implemented yet.');
    }
}
```

### app/Domains/Webhook/Services/ChannelAdapterResolver.php

```php
<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Services;

use App\Domains\Shared\Enums\PlatformEnum;
use App\Domains\Webhook\Contracts\ChannelAdapterInterface;
use App\Domains\Webhook\Exceptions\AdapterNotFoundException;
use Illuminate\Contracts\Container\Container;

final class ChannelAdapterResolver
{
    /**
     * @param array<string, class-string<ChannelAdapterInterface>> $adapterMap
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $adapterMap,
    ) {}

    public function resolve(PlatformEnum $platform): ChannelAdapterInterface
    {
        $class = $this->adapterMap[$platform->value] ?? null;

        if ($class === null) {
            throw new AdapterNotFoundException(
                "No adapter registered for platform: {$platform->value}"
            );
        }

        return $this->container->make($class);
    }
}
```

### app/Domains/Webhook/Exceptions/

```php
<?php declare(strict_types=1);
// Все три в namespace App\Domains\Webhook\Exceptions

final class InvalidSignatureException extends \RuntimeException {}
final class AdapterNotFoundException extends \RuntimeException {}
final class WebhookRegistryException extends \RuntimeException {}
```

### app/Domains/Webhook/Http/WebhookController.php

```php
<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Http;

use App\Domains\Webhook\Exceptions\AdapterNotFoundException;
use App\Domains\Webhook\Exceptions\InvalidSignatureException;
use App\Domains\Webhook\Exceptions\WebhookRegistryException;
use App\Domains\Webhook\DTOs\WebhookRegistryEntry;
use App\Domains\Webhook\Jobs\IncomingMessageJob;
use App\Domains\Webhook\Services\ChannelAdapterResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Redis;

final class WebhookController extends Controller
{
    public function __construct(
        private readonly ChannelAdapterResolver $resolver,
    ) {}

    public function __invoke(Request $request, string $platform, string $hash): JsonResponse
    {
        try {
            return $this->handle($request, $hash);
        } catch (InvalidSignatureException|AdapterNotFoundException|WebhookRegistryException $e) {
            report($e);
            return response()->json(['ok' => true]);
        }
    }

    private function handle(Request $request, string $hash): JsonResponse
    {
        // 1. Resolve registry
        $entry = $this->resolveRegistry($hash);

        // 2. Resolve adapter
        $adapter = $this->resolver->resolve($entry->platform);

        // 3. Verify signature — до idempotency, иначе forged payload отравит кеш
        $adapter->verifySignature($request, $entry->secretToken);

        // 4. Extract update_id из raw payload
        $updateId = (string) ($request->json('update_id', ''));

        // 5. Idempotency check
        if ($updateId !== '' && !$this->markProcessed($entry->channelId, $updateId)) {
            return response()->json(['ok' => true]);
        }

        // 6. Parse DTO
        $message = $adapter->parse($request);

        // 7. Dispatch
        IncomingMessageJob::dispatch(
            message: $message,
            tenantId: $entry->tenantId,
            channelId: $entry->channelId,
        )->onQueue('flow.execution');

        // 8. Всегда 200
        return response()->json(['ok' => true]);
    }

    private function resolveRegistry(string $hash): WebhookRegistryEntry
    {
        $raw = Redis::get("webhook:{$hash}");

        if (!$raw) {
            throw new WebhookRegistryException(
                "Webhook registry not found for hash: {$hash}"
            );
        }

        return WebhookRegistryEntry::fromRedis($raw);
    }

    /**
     * Возвращает true если ключ установлен впервые (не дубликат).
     * Возвращает false если уже обработан.
     */
    private function markProcessed(string $channelId, string $updateId): bool
    {
        return (bool) Redis::set(
            "processed:{$channelId}:{$updateId}",
            '1',
            'EX', 86400,
            'NX',
        );
    }
}
```

### app/Domains/Webhook/Jobs/IncomingMessageJob.php

```php
<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Jobs;

use App\Domains\Contact\Contracts\ContactServiceInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Webhook\DTOs\IncomingMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class IncomingMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        public readonly IncomingMessage $message,
        public readonly string $tenantId,
        public readonly string $channelId,
    ) {}

    public function handle(
        TenantRepositoryInterface $tenantRepository,
        TenantSwitcher $switcher,
        ContactServiceInterface $contactService,
    ): void {
        // TenantNotFoundException из getById — не ловим:
        // если тенант не найден по ID из Redis registry — данные сломаны,
        // retry не поможет, пусть улетает в failed jobs.
        $tenant = $tenantRepository->getById($this->tenantId);

        $switcher->runForTenant($tenant, function () use ($contactService): void {
            $contactService->findOrCreateChannelContact(
                platform: $this->message->platform,
                externalChatId: $this->message->externalChatId,
                externalUserId: $this->message->externalUserId,
                channelId: $this->channelId,
                meta: $this->message->meta,
            );

            // Handoff → Task 14 (TriggerResolver / FlowEngine)
            // Placeholder: здесь будет вызов FlowEngine после Task 14
        });
    }
}
```

### app/Domains/Webhook/Providers/WebhookServiceProvider.php

```php
<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Providers;

use App\Domains\Webhook\Adapters\TelegramChannelAdapter;
use App\Domains\Webhook\Adapters\WhatsAppChannelAdapter;
use App\Domains\Webhook\Services\ChannelAdapterResolver;
use Illuminate\Support\ServiceProvider;

final class WebhookServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelAdapterResolver::class, function ($app) {
            return new ChannelAdapterResolver(
                container: $app,
                adapterMap: [
                    'telegram' => TelegramChannelAdapter::class,
                    'whatsapp' => WhatsAppChannelAdapter::class,
                ],
            );
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/webhook.php');
    }
}
```

### app/Domains/Webhook/routes/webhook.php

```php
<?php

use App\Domains\Webhook\Http\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/{platform}/{hash}', WebhookController::class)
    ->name('webhook.incoming');
```

---

## Существующие классы из других задач (только сигнатуры)

Cursor должен использовать эти классы как есть — не переписывать, не дублировать.

### TenantSwitcher (Task 02/03) — уже существует

```php
// App\Domains\Tenancy\Services\TenantSwitcher
final readonly class TenantSwitcher
{
    public function runForTenant(TenantInterface $tenant, Closure $callback): mixed;
}
```

`runForTenant` — stack-based: переключает DB-схему и TenantContext, гарантирует restore через `finally`. Принимает `TenantInterface`, не `string`.

### TenantRepositoryInterface (Task 02/03) — уже существует

```php
// App\Domains\Tenancy\Contracts\TenantRepositoryInterface
interface TenantRepositoryInterface
{
    public function getById(string $id): TenantInterface;  // throws TenantNotFoundException
    public function findById(string $id): ?TenantInterface;
    public function findBySlug(string $slug): ?TenantInterface;
    public function findAllActive(): array;
    public function existsAny(): bool;
    public function save(TenantInterface $tenant): void;
}
```

### ContactServiceInterface (Task 07) — уже существует

```php
// App\Domains\Contact\Contracts\ContactServiceInterface
interface ContactServiceInterface
{
    public function findOrCreateChannelContact(
        PlatformEnum $platform,
        string $externalChatId,
        string $externalUserId,
        string $channelId,
        array $meta = [],
    ): ContactInterface;
}
```

### PlatformEnum (Task 07) — уже существует

```php
// App\Domains\Shared\Enums\PlatformEnum
enum PlatformEnum: string
{
    case Telegram = 'telegram';
    case WhatsApp = 'whatsapp';
    case Email    = 'email';
}
```

---

## Критерий готовности

Добавление нового канала требует только `NewChannelAdapter implements ChannelAdapterInterface` + регистрация в `adapterMap`. Если новый транспорт вынуждает менять Job — граница Task 08 нарушена.

---

## Внешние зависимости

| Что | Задача |
| --- | --- |
| `TenantSwitcher::runForTenant()` | Task 02/03 ✓ |
| `TenantRepositoryInterface::getById()` | Task 02/03 ✓ |
| `ContactServiceInterface::findOrCreateChannelContact()` | Task 07 ✓ |
| `PlatformEnum` | Task 07 ✓ |
| Redis webhook registry write | Task 06 ✓ |
| FlowEngine dispatch в Job | Task 14 |
| Octane worker lifecycle | Task 08a |