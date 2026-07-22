# 08. Node Test Matrix

Матрица, по которой стоит прогонять **каждую** новую ноду.

Идея: тесты должны проверять не только happy path, но и устойчивость runtime под retry/concurrency/битый config.

---

## 1) Базовый минимум для любой ноды

| Категория      | Что проверить                             | Где лучше тестировать        |
|----------------|-------------------------------------------|------------------------------|
| Contract       | `type`, `version`, регистрация в registry | Unit (`NodeHandlerRegistry`) |
| Happy path     | корректный `NodeExecutionResult`          | Unit (handler)               |
| Invalid config | корректная ошибка/failed path             | Unit (handler)               |
| Source handle  | ожидаемый handle для ветвления            | Unit (handler)               |
| State changes  | правильные ключи/значения                 | Unit (handler)               |
| Integration    | нода реально исполняется в flow loop      | Feature (`FlowEngine`)       |

---

## 2) Расширенный checklist по типам нод

### A. Input-like

- без `incoming` -> `Waiting`
- с валидным `incoming` -> `Executed + default`
- с невалидным `incoming` -> `invalid`/`Waiting` по контракту
- корректная запись результата в `flow.*`
- повтор того же update не создаёт двойной side-effect

### B. Branch-like (condition/switch)

- совпадение по primary rule
- fallback rule (`default`)
- edge cases по типам (`string/number/null/empty`)
- логирование `logResolved`

### C. Sender-like

- формирование payload из config/state/language
- idempotent resend (повтор не отправляет второй раз)
- корректная обработка ошибки sender-а
- корректные state markers (`system.sent_messages` и т.д.)

### D. External-call

- успешный ответ внешнего сервиса
- timeout/network failure
- не-2xx ответ
- retry-safe поведение (нет дублей на стороне внешнего сервиса)

### E. Delay/async

- первая итерация ставит schedule marker
- повторная итерация не планирует заново
- resume path продолжает execution корректно
- нет бесконечного waiting из-за missing marker logic

---

## 3) Тесты на graph compatibility

Проверки через `FlowDefinitionValidator`:

- нода с новым `type@version` валидируется после регистрации handler-а,
- отсутствующий handler -> expected validation error,
- все required transitions присутствуют,
- нет дублирующихся transitions у одного source.

---

## 4) Concurrency и retry matrix

| Сценарий                                | Ожидаемое поведение                                             |
|-----------------------------------------|-----------------------------------------------------------------|
| Повтор job после lock miss              | результат консистентный, без дублей                             |
| Optimistic lock conflict                | orchestration retry приводит к корректному финальному состоянию |
| Два почти одновременных inbound события | нет двойной отправки/двойного изменения state                   |
| Повтор одного update id                 | dedup/идемпотентность не даёт повторных side-effects            |

---

## 5) Что обязательно мокать/фейкать в unit тестах

- внешний HTTP клиент,
- outbound message sender,
- time (`now()`/таймеры) для delay-like сценариев,
- data accessor для `module.*` путей.

Это делает тесты быстрыми и детерминированными.

---

## 6) Рекомендуемая структура тестов

### Unit (handler-specific)

- файл: `tests/Unit/Domains/Flow/<NodeName>HandlerTest.php`
- покрывает execute-логику, config validation, handle selection.

### Unit (definition/validation)

- файл рядом с `FlowDefinitionValidatorTest`
- проверяет, что definition с новой нодой валиден/не валиден в нужных кейсах.

### Feature (engine integration)

- файл: `tests/Feature/Domains/Flow/...`
- проверяет end-to-end кусок: session -> executeLoop -> state/status/logs.

---

## 7) Gate перед merge новой ноды

- [ ] Node handler зарегистрирован и виден в `NodeTypesController`.
- [ ] Есть unit тесты на happy + invalid + edge paths.
- [ ] Есть integration test через `FlowEngine` (минимум один).
- [ ] Проверена идемпотентность/повтор запуска.
- [ ] Документация по config и outputs обновлена.

Если хотя бы один пункт не закрыт - ноду лучше не считать готовой.
