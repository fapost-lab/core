# 08 · Acceptance Criteria

V1 считается готовым когда:

- [ ] Все 10 node types реализованы и зарегистрированы в NodeHandlerRegistry
- [ ] CallTransportRegistry содержит http и handler транспорты
- [ ] PHPat правило проверяет реализацию NodeHandlerInterface всеми зарегистрированными
- [ ] Все node types имеют unit-тесты per handler (happy path + edge cases)
- [ ] Validation flow_definition покрывает все 6 уровней (см. [06-validation.md](06-validation.md))
- [ ] Group storage с GIN index готов к запросам (см. [05-group-storage.md](05-group-storage.md))
- [ ] Subflow lifecycle тесты: success / cancelled / failed / timeout / lock transfer
- [ ] Concurrency тесты: distributed lock contention, heartbeat extension, lock acquisition backoff retry, optimistic lock на session.version, /reset во время active execution
- [ ] Routing pipeline тесты: 6 шагов end-to-end, drop policy, busy notice
- [ ] Global commands тесты: built-in lookup priority, tenant override, action types
- [ ] Typing indicator тесты: heartbeat refresh, lifecycle (start/refresh/stop)
- [ ] Idempotency contract тесты (V1 minimal): `idempotencyKey` присутствует в `MessageSender.send()` и `CallContext`, в HTTP transport кладётся в `Idempotency-Key` header. Redis-based dedup — V1.x.
- [ ] State namespace resolver тесты для всех источников (contact / flow / rag / call / module)
- [ ] ScopedStateWriter тесты: read-after-write, multi-namespace flush, transaction rollback
- [ ] Integration test: flow с send_message + input + branch + assign + end отрабатывает end-to-end
- [ ] Integration test: flow с subflow вызывает child, parent suspended, child completes, parent resumed
- [ ] Documentation в Notion обновлена

---

## Связано с

- [[06-validation]] — валидация flow
- [[../../plans/flow-engine/implementation-plan]] — план реализации
- [[../../plans/flow-engine/synthesis]] — итоговый синтез
