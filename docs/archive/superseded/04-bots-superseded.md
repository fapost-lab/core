# 04 — Боты

> ⚠️ Этот раздел устарел. Актуальная модель: **Assistant Domain — Architectural Model** (страница в этом же разделе).
> 

Архитектурное решение принято в марте 2026: `Bot` как верхний доменный термин упразднён.

- `Assistant` — верхний бизнес-агрегат
- `Channel` — transport endpoint, подчинён `Assistant`
- Таблицы БД: `assistants` + `channels`
- Задача реализации: 06b — Assistant & Channel Domain