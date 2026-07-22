# 07 · Migration & Backward Compat

## 7.1 Версионирование

Каждый node в snapshot содержит `version`. Engine резолвит handler по `(type, version)`:

```php
$handler = $registry->resolve($node['type'], $node['version']);
```

`supportedVersions()` позволяет одному handler обслуживать несколько версий конфига (если реализуется legacy-совместимое чтение). Default — `supportedVersions() = [version()]`.

## 7.2 Breaking changes

При breaking change config:
- Создаётся новый handler с version+1
- Регистрируется параллельно со старым: `registry->register(new SendMessageHandlerV1())` + `registry->register(new SendMessageHandlerV2())`
- Существующие flow_definitions продолжают резолвить v1
- Новые flow_definitions сохраняются с version=2 (UI-builder использует latest)

## 7.3 Deprecated handler removal

Handler можно удалить только когда:
- `flow_active_node_stats` показывает 0 для (type, version)
- Нет `flow_sessions WHERE status IN ('waiting_input', 'paused', 'paused_subflow')` использующих этот type@version

Это `HandlerVersionContract` (Phase 2 plan, task 09).

---

## Связано с

- [[09-flow-versioning]] — архитектура версионирования
- [[03-node-handler-interface]] — Handler Version Contract
- [[../../plans/flow-engine/brownfield-audit]] — аудит существующих нод
