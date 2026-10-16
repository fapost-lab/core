---
id: convention-channel-driver-boundary
type: convention
status: active
domains:
  - channels
paths:
  - "app/Domains/Channels/Telegram/**"
  - "app/Domains/Channels/WhatsApp/**"
  - app/Domains/Webhook/Contracts/ChannelAdapterInterface.php
summary: Channel drivers are reached only through MessageSenderInterface and throw on unsupported methods
reviewed_at: 2026-10-16
---
# Channel driver boundary

A messaging platform integration: the webhook side (`ChannelAdapterInterface`) and the
provider sender behind `MessageSenderInterface`.

## Practice

```
MUST:     be reached for outbound messages only through MessageSenderInterface
MUST:     throw on a method it does not support; never degrade silently
MUST NOT: be called directly by Flow, Broadcasting or any other domain for sending
MUST NOT: log, store or put into an exception message a payload's `media.url` (a signed public link)
MUST NOT: mint a media link itself; take the one Core put in the payload
```

## Example

`WhatsAppAdapter` is a stub: every method throws `LogicException('WhatsApp adapter is not
implemented yet.')` instead of returning an empty result. Flow sends through
`MessageSenderInterface`, which reserves the idempotency key and applies the per-chat rate
limit before `TelegramSender` is called.

## Rationale

Idempotency and the provider rate limit live in `MessageSender`; a direct call to a driver skips
both, and the result is a duplicate or a throttled send far from the code that caused it. A
silent no-op on an unsupported method looks like a delivered message; an exception fails where
the gap is.
