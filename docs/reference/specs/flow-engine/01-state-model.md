# 01 · State Model

## 1.1 Namespaces в JSON snapshot и runtime

Все nodes пишут и читают через единый набор namespace. Эти namespace **не показываются** пользователю в UI.

| Namespace | Владелец | Persistence | Назначение |
|-----------|----------|-------------|------------|
| `system.*` | Engine | session JSON | started_at, retry_count, current_node, node_attempts |
| `flow.*` | Compiler-generated nodes | session JSON | session-scoped scratchpad для runtime данных |
| `rag.*` | rag_query node | session JSON | found, confidence, answer, intent последнего запроса |
| `call.*` | call node | session JSON | response payload последнего вызова |
| `contact.*` | resolver через Contact модель | persistent (Contact) | Свойства контакта |
| `module.<name>.*` | DataAccessor | external (модуль) | Канонические данные модуля |

`flow_sessions.state` JSON содержит только `system`, `flow`, `rag`, `call`. `contact.*` и `module.*` резолвятся через accessors при чтении и записываются через writers — не дублируются в session state.

**Persistence model (см. ADR State Writer Semantics):** writes immediate. `contact.*` коммитится в свою BEGIN-UPDATE-COMMIT транзакцию per write. Session-state (`flow.*`, `system.*`, `rag.*`, `call.*`) мутирует in-memory `FlowSession` объект, persist одним `UPDATE flow_sessions` в конце выполнения ноды (с optimistic-lock версионированием). Подробнее — [03-node-handler-interface.md](03-node-handler-interface.md), раздел ScopedStateWriter.

## 1.2 Contact namespace

Плоская адресация в expression и save_to:

```
contact.<key>                    — leaf поле в attributes
contact.<group>.<key>            — nested JSON в attributes
contact.meta.<key>               — данные платформы (read-only для пользователя)
```

Resolver при чтении `contact.<key>`:

1. Если `<key>` — известное поле модели (id, channel_id, channel) → колонка модели
2. Иначе walk по `attributes` JSON path
3. Если на пути встречается non-object value на промежуточном сегменте → null
4. Если ключ не найден → null

Resolver при записи `contact.<key>` (assign/input):

1. Если `<key>` — reserved (id, channel_id, channel, meta.*) → validation error при сохранении flow_definition
2. Иначе walk по path в `attributes`, создавая объекты по дороге
3. Если на пути встречается non-object value на промежуточном сегменте → runtime error, session failed

## 1.3 Глубина вложенности

Группы — максимум 1 уровень: `contact.<group>.<field>`.

```
contact.name                ✓
contact.form.input1         ✓
contact.form.address.city   ✗ слишком глубоко
```

Validator при сохранении flow_definition проверяет `save_to` на максимум 1 точку после `contact.`.

## 1.4 Reserved keys

**Reserved для contact:**
- Поля модели: `id`, `tenant_id`, `channel_id`, `channel`
- Группа: `meta` (owned by webhook ingress — username, first_name из платформы)

Список фиксируется в коде. Validator проверяет на сохранении.

---

## Связано с

- [[00-overview]] — обзор flow engine
- [[10-state-writer-semantics]] — семантика state writer
- [[04-session-state-machine]] — диаграмма state machine
- [[05-group-storage]] — group storage
- [[specs/flow-engine/05-group-storage]] — group storage спека
- [[diagrams/04-session-state-machine]] — диаграмма state machine
