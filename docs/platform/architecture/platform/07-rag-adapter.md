# 07 — RAG — детерминированный адаптер

## Принцип

Flow engine детерминирован. LLM недетерминирован. RAG-нода никогда не кладёт raw LLM output в state flow.

---

## Контракт

```php
interface RagAdapterInterface {
    public function query(string $prompt, RagQueryContext $context): StructuredRagResult;
}
```

| Компонент | Описание |
| --- | --- |
| **StructuredRagResult** | found (bool), confidence (low/medium/high), answer (string), intent? (string), metadata (array) |
| **Нормализатор** | Часть адаптера, не ноды. Raw LLM output → StructuredRagResult |

---

## Pipeline

1. `rag_query` нода — берёт вопрос из state
2. `RagAdapter::query()` — вызывает провайдера
3. Нормализация внутри адаптера
4. Результат сохраняется в `rag.*` namespace
5. `condition` читает `rag.found` / `rag.confidence` — детерминированный переход
6. `send_message` берёт `rag.answer`

---

## Схема

| Таблица | Описание |
| --- | --- |
| **knowledge_bases** | id, tenant_id, name, provider, config (JSON) |
| **knowledge_documents** | title, content, embedding_id, knowledge_base_id, meta |

> ⚠ Открытый вопрос: OpenAI Assistants API vs pgvector. Решить до задачи 19.
>

---

## Связано с

- [[09-rag-query]] — нода rag_query
- [[05-foundation-contract-package]] — RagAdapterInterface в Foundation
- [[12-solutions-modules]] — RAG как Feature