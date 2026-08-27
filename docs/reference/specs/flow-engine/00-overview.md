# 00 · Обзор

Спецификация описывает 10 типов нод движка Flow Engine для V1. Каждая нода имеет жёсткий контракт: тип, версия, конфиг, поведение, output handles. Все handlers in-memory зарегистрированы через `NodeHandlerRegistry` (см. ADR Handler Versioning).

## Принципы

- Минимум типов — меньше versioning surface
- Один контракт = один node type (Branch вместо condition+switch, Assign вместо set_attribute+transform, Call вместо integration+action)
- Pluggable extension points: транспорты для Call, типы input
- UI скрывает namespace и runtime детали — пользователь видит только бизнес-концепты

---

## Связано с

- [[README]] — главный README flow engine
- [[01-state-model]] — state model
- [[02-common-concepts]] — общие концепции
- [[10-state-writer-semantics]] — ADR state writer semantics
- [[08-expression-language]] — ADR expression language
