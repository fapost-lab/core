# Node · `rag_query`

Запрос к базе знаний через `RagAdapterInterface`.

**Type:** `rag_query`
**Version:** 1
**Idempotent:** depends on adapter (RAG providers обычно идемпотентны для одинакового prompt)

## Config

```json
{
  "knowledge_base_id": "01HQ_kb_main",
  "query": "{{flow.last_user_message}}",
  "options": {
    "min_confidence": "low",
    "max_tokens": 500
  }
}
```

**Поля:**

| Поле | Тип | Required | Описание |
|------|-----|----------|----------|
| `knowledge_base_id` | string | yes | ULID FK на knowledge_bases. **Literal**, не Expression |
| `query` | Expression | yes | Resolved prompt |
| `options` | object | no | Adapter-specific опции |

## Output handles

- `success` — adapter вернул `StructuredRagResult` с `found=true`
- `not_found` — `found=false`
- `error` — ошибка adapter

## Behavior

1. Resolve `query` Expression
2. Загрузить `knowledge_base` по id, получить provider
3. Получить RagAdapter из registry по provider
4. Вызвать `adapter.query(query, RagQueryContext{tenant, contact, options})`
5. Adapter возвращает `StructuredRagResult{found, confidence, answer, intent, metadata}`
6. Записать в `state.rag.*` через `result.stateChanges` (handler возвращает delta, engine применяет атомарно):
   - `rag.found` = `result.found`
   - `rag.confidence` = `result.confidence`
   - `rag.answer` = `result.answer`
   - `rag.intent` = `result.intent`
   - `rag.metadata` = `result.metadata`
7. Переход:
   - `success` если `result.found`
   - `not_found` если `!result.found`
   - `error` при exception от adapter

## Validation flow_definition

- `knowledge_base_id` существует и принадлежит этому тенанту
- `options` валидны для provider данного KB

---

## Связано с

- [[README]] — nodes README
- [[07-rag-adapter]] — архитектура RAG adapter
- [[05-foundation-contract-package]] — RagAdapterInterface в Foundation
- [[12-solutions-modules]] — RAG как Feature
