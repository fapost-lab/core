# Message queues & sender

> Архив Notion. Актуальная документация: [[10-message-pipeline]]


Domain: Messaging
Phase: 3 — Messaging Pipeline
Status: Готово
Task №: 16

## Goal

Построить изолированный pipeline исходящих сообщений так, чтобы transactional-ответы никогда не блокировались broadcast-трафиком.

## Problem

Одна общая очередь для всех исходящих сообщений создаёт queue starvation: broadcast заполняет очередь, transactional-ответы ждут, webhook retry усиливают нагрузку, session lock начинают конкурировать, SLA ответа становится нестабильным. Без изоляции очередей flow runtime ненадёжен под нагрузкой.

## Scope

Задача покрывает только MessageSender и изоляцию очередей. Channel connectors (TelegramSender, WhatsAppSender) — в задаче 16a.

## Architectural decisions

**MessageSenderInterface → fapost/foundation.** Контракт нужен внешним модулям (HR и будущие) — без этого Core становится bottleneck и нарушается plugin architecture.

**Rate limiter key → `rate:{channel_id}:{chat_id}`.** Лимиты провайдеров привязаны к токену/боту. Ассистент может иметь несколько каналов — ключ по `assistant_id` создавал бы искусственный bottleneck.

**Backpressure → Redis inflight counter.** BroadcastSendJob делает `INCR queue:messaging.transactional:inflight` при старте и `DECR` при завершении. Если `inflight > threshold` → `release(delay)`. Порог в конфиге: `messaging.transactional_max_inflight = 100`.

**Idempotency → фиксация до dispatch.** Handler возвращает pending messages через `NodeExecutionResult`. Engine пишет `system.sent_messages` (idempotency keys) и сохраняет сессию. Только после этого — dispatch job. `SendTransactionalMessageJob` не пишет в `flow_sessions` — только delivery layer.

**Handler не отправляет напрямую.** `send_message` handler → engine записывает в `system.sent_messages` → dispatch `SendTransactionalMessageJob` → `messaging.transactional`. Прямой вызов провайдера внутри handler запрещён.

## Contracts in fapost/foundation

```
namespace FAPost\Foundation\Messaging;

interface MessageSenderInterface
{
    public function send(OutboundMessage $message): DeliveryResult;
}

interface ProviderSenderInterface
{
    public function channelType(): string;
    public function deliver(OutboundMessage $message): DeliveryResult;
}

final class OutboundMessage extends Data
{
    public function __construct(
        public readonly string $idempotencyKey,  // генерирует engine до dispatch
        public readonly string $tenantId,
        public readonly string $channelId,        // ключ rate limiter
        public readonly string $channelType,      // telegram|whatsapp
        public readonly string $chatId,
        public readonly MessagePayload $payload,
        public readonly array $metadata = [],     // flow_session_id, node_id
    ) {}
}

final class DeliveryResult extends Data
{
    public function __construct(
        public readonly bool $sent,
        public readonly ?string $providerMessageId = null,
        public readonly bool $duplicate = false,  // idempotency hit
        public readonly ?string $error = null,
    ) {}
}
```

## Core implementation

**MessageSender** (app/Domains/Messaging/MessageSender.php) — реализует `MessageSenderInterface`. Ответственности: резолвинг провайдера по `channelType`, проверка idempotency key в Redis (`SET NX TTL=24h`), rate limiting по `rate:{channel_id}:{chat_id}`, вызов `ProviderSenderInterface::deliver()`, retry policy при временных ошибках провайдера.

**SendTransactionalMessageJob** (app/Jobs/Messaging/SendTransactionalMessageJob.php) — диспатчится на очередь `messaging.transactional`. Принимает `OutboundMessage`. Вызывает `MessageSenderInterface::send()`. Не пишет в `flow_sessions`.

**BroadcastSendJob** (app/Jobs/Messaging/BroadcastSendJob.php) — диспатчится на `messaging.broadcast`. Один job = один получатель. Перед отправкой проверяет `queue:messaging.transactional:inflight`. При превышении порога — `$this->release($delay)`. INCR при старте, DECR в `finally`.

**MessageSenderServiceProvider** — регистрирует `MessageSenderInterface → MessageSender`, собирает `ProviderSenderInterface` из tagged bindings.

## Queue topology

```
HIGH workers:
  flow.execution
  messaging.transactional

MEDIUM workers:
  sync.external
  scheduled.triggers

LOW workers:
  messaging.broadcast
  messaging.system
```

Конфигурируется в `config/horizon.php`. Отдельные worker pools — transactional никогда не конкурирует с broadcast.

## File structure

```
packages/fapost/foundation/src/Messaging/
  MessageSenderInterface.php
  ProviderSenderInterface.php
  OutboundMessage.php
  MessagePayload.php
  DeliveryResult.php

app/Domains/Messaging/
  MessageSender.php
  MessageSenderServiceProvider.php

app/Jobs/Messaging/
  SendTransactionalMessageJob.php
  BroadcastSendJob.php

config/
  horizon.php  (queue topology)
  messaging.php  (transactional_max_inflight, rate limits)
```

## What NOT to do

- Не использовать одну общую очередь для transactional и broadcast
- Не вызывать провайдера напрямую внутри `send_message` handler
- Не писать в `flow_sessions` из `SendTransactionalMessageJob`
- Не фиксировать idempotency key после отправки — только до dispatch
- Не использовать `Queue::size()` для backpressure — только Redis inflight counter
- Не делать rate limiter по `assistant_id` — только по `channel_id`
- Не реализовывать `TelegramSender` / `WhatsAppSender` в этой задаче — они в задаче 16a

## Depends on

Task 13 (Built-in node handlers — `send_message` handler)

## Acceptance criteria

- Broadcast не задерживает transactional-ответы
- `messaging.transactional` остаётся стабильной под broadcast-нагрузкой
- Retry не дублирует отправку (idempotency key)
- Rate limits провайдера соблюдаются превентивно
- `BroadcastSendJob` уступает при насыщении transactional queue
- phpat-правило: handler не может зависеть от `ProviderSenderInterface` напрямую