# 13 — Открытые вопросы

| Вопрос | Суть | Когда решать |  |  |
| --- | --- | --- | --- | --- |
| **RAG провайдер** | OpenAI Assistants vs pgvector | До задачи 19 |  |  |
| **Enterprise-клиент** | Legacy или первый тенант в новой системе? | До Фазы 1 |  |  |
| **Backpressure threshold** | Per tenant или глобальный? | Задача 18 |  |  |
| **Cross-module migration** | Linter в CI или runtime check? | Задача 25 |  |  |
| **Миграции при отключении модуля** | Таблицы остаются или soft-delete? | Задача 22 |  |  |
| **Единый manifest contract** | Адаптация SaaS vs self-hosted | Задача 25 |  |  |
| **Namespace ролей модулей** | Коллизии при одинаковом value | Задача 21 |  |  |
| **Второй Solution после HR** | Зависит от обратной связи рынка | После Фазы 4 |  |  |
| **Assistant translation layer** | Сейчас нет слоя assistant override: один translation key может иметь разные формулировки per assistant (Sales / HR / Support tone). Текущий pipeline: tenant base → flow inline → locale resolve — промежуточный слой отсутствует. Кандидат схемы: unified `translations` (scope = tenant \ | assistant \ | flow, scope_id, key, locale, value). Не blocker, но долг при активном использовании multilingual. | До активации multilingual |

---

## Связано с

- [[01-overview-layers]] — общая архитектура
- [[09-out-of-scope-and-open-questions]] — открытые вопросы flow engine
- [[ROADMAP]] — дорожная карта