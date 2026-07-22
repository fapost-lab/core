# Broadcast engine

> Архив Notion. Актуальная документация: [[ROADMAP]]


Depends on: 16
Domain: Messaging
Phase: 3 — Messaging Pipeline
Sprint: 7
Status: К реализации
Task №: 19

## Состав

- `BroadcastSendJob` — один job = один получатель
- Rate limiter per `(bot_id, chat_id)` Redis — превентивно, не по ошибке от Telegram
- Backpressure: проверка длины transactional queue перед отправкой, release с delay при насыщении

## Открытый вопрос

> ⚠ Backpressure threshold: per tenant или глобальный? Решить при реализации.
>