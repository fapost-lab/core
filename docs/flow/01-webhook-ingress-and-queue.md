# 01. Webhook Ingress и постановка в очередь

Этот этап должен быть максимально быстрым и stateless: принять webhook, провалидировать, дедуплицировать и отправить в
очередь.

## Что происходит по шагам

1. HTTP запрос приходит в `WebhookController::__invoke(Request $request, string $channel, string $hash)`.
2. По `hash` резолвится routing-entry (`tenant_id`, `schema`, `assistant_id`, `channel_id`, `platform`, `secret_token`)
   через `WebhookRegistryResolver`.
3. Проверяется подпись провайдера (`verifySignature`).
4. Из payload извлекается idempotency key (`extractIdempotencyKey`).
5. Выполняется Redis dedup (`SET processed:{key} NX EX 86400`).
6. Если это не дубль - диспатчится `IncomingMessageJob` в очередь `flow.execution`.
7. HTTP endpoint всегда отвечает `{"ok": true}`.

## Почему так

- **Ingress не трогает tenant DB и Eloquent**, чтобы не тратить время на тяжёлую логику в HTTP hot path.
- **Queue boundary** защищает от всплесков нагрузки и делает retry управляемым через Horizon.
- **Dedup на входе** защищает от повторных webhook от провайдера (частое поведение Telegram/WhatsApp).

## Ключевые классы и методы

| Класс                     | Метод                | Роль                                              |
|---------------------------|----------------------|---------------------------------------------------|
| `WebhookController`       | `__invoke`, `handle` | Приём запроса, signature + dedup + dispatch       |
| `WebhookRegistryResolver` | `resolve`            | Резолв канала и tenant-контекста по `public_hash` |
| `ChannelAdapterResolver`  | `resolve`            | Выбор адаптера платформы (telegram/whatsapp/...)  |

## Как резолвится webhook hash

`WebhookRegistryResolver::resolve($hash)`:

1. Сначала читает Redis ключ `webhook:{hash}`.
2. Если miss - читает landlord через `WebhookRegistryReaderInterface`.
3. Самовосстанавливает кэш (`Redis::set(webhook:{hash}, ...)`) для следующих запросов.
4. Использует краткий lock `warming:{hash}` (5 сек) против thundering herd.

Это означает: БД - source of truth, Redis - hot-cache для ingress.

## Где включается очередь

В `WebhookController`:

- `dispatch(new IncomingMessageJob(...))->onQueue('flow.execution')`

А в `config/horizon.php`:

- `flow.execution` и `messaging.transactional` лежат в high-priority supervisor.

## Технические ключи Redis

| Ключ                          | Назначение                           | TTL               |
|-------------------------------|--------------------------------------|-------------------|
| `webhook:{hash}`              | Кэш registry entry для маршрутизации | без TTL (persist) |
| `processed:{idempotency_key}` | Дедуп inbound события                | 86400 сек         |
| `warming:{hash}`              | Короткий lock для self-heal кэша     | 5 сек             |

## Что проверять при проблемах ingress

- Неправильный `public_hash` -> `WebhookRegistryException`.
- Неверный `secret_token`/signature -> `InvalidSignatureException`.
- Повтор одного и того же update -> dedup и ранний `ok=true` без job.
