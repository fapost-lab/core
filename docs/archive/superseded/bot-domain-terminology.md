# ⚠️ Bot Domain — Terminology Reference [superseded]

Зафиксировано: март 2026. Актуально до Phase 2.

---

## Текущее состояние (Phase 1)

Платформа использует термин **Bot Domain** как техническое название домена, отвечающего за подключение внешних каналов коммуникации и приём входящих сообщений.

---

## Что такое Bot в коде

`Bot` — это **transport-bound runtime endpoint**. Сущность описывает не интеллектуального ассистента, а подключённый канал доставки сообщений.

Содержит:

- `channel` type (`telegram`, `whatsapp`)
- `token` / credentials
- `webhook_public_hash`
- channel-specific `config`
- `is_active` state
- `default_flow_id` binding

---

## Семантическое правило (Phase 1)

| Сущность | Ответственность |
| --- | --- |
| **Bot** | Подключённый communication endpoint |
| **Flow** | Логика обработки |
| **Session** | Runtime execution state |

---

## Terminology split: код vs UI

| Слой | Термин |
| --- | --- |
| Domain name | `Bot` |
| Model | `Bot` |
| Service | `BotService` |
| Table | `bots` |
| **UI label** | **Каналы** |

Это **intentional terminology split** между code layer и UI layer. Пользователь в интерфейсе выполняет действие подключения канала, а не создания «бота».

---

## План эволюции

На следующих этапах возможна отдельная бизнес-сущность `Assistant`, которая станет верхним уровнем:

```
Assistant
  → Channels   (текущий Bot)
  → Flows
  → Sessions
```

Текущий `Bot` может быть:

- сохранён как внутренняя transport-сущность
- переименован в `Channel` после стабилизации runtime-модели

**До Phase 2 — переименование не производится.**

---

> ⚠ Не переименовывать `Bot` → `Channel` или `Assistant` до явного решения в Phase 2. Изменение затронет: модели, миграции, сервисы, Redis-ключи (`bot_id`), очереди (`session_lock:{tenant}:{contact}:{bot}`), тесты.
>