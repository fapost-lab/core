# 05 — Контакты (Contacts)

## Описание

**Contact** — участник диалога. Идентифицируется по ID в канале. Не имеет аккаунта в панели.

---

## Схема

| Компонент | Описание |
| --- | --- |
| **contacts** | id, tenant_id, channel_id, channel, meta (JSON: имя, username), attributes (JSON) |
| **contact_groups** | Группы для сегментированных рассылок |
| **contact_group_members** | Pivot: contact ↔ group |

---

## Важно

> ✕ `contacts.attributes` — НЕ кеш модульных данных. Только данные, собранные платформенными нодами (`input`, `set_attribute`). Модули не пишут сюда напрямую.
> 

---

## Сегментация

Группы / Теги / Сегменты — `ContactSegmentResolver` резолвит получателей для broadcast.

---

## Связано с

- [[04-assistant-domain]] — контакты принадлежат ассистенту
- [[02-input]] — input нода сохраняет данные контакта
- [[05-assign]] — assign нода обновляет атрибуты контакта
- [[set-tag]] — set_tag нода работает с contact_tags
- [[03-id-strategy-ulid]] — ID стратегия для контактов
- [[07-media-domain-asset-cache]] — медиа от контактов
- [[15-multilingual]] — мультиязычность (contacts.language)
- [[specs/flow-engine/nodes/12-set-tag]] — спека set_tag ноды