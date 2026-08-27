# Flow Runtime Docs

Стабильная документация для инженеров по runtime-цепочке обработки входящих сообщений:

`Webhook -> Queue -> Worker -> Session/Trigger -> Flow Engine -> Outbound Message`

Детальные спеки живут в [`../../../reference/specs/flow-engine`](../../../reference/specs/flow-engine), а статусы нод и открытые задачи — в
[`../../TASKS.md`](../../TASKS.md). Если этот раздел расходится с полной документацией, проверяй код и обновляй устаревший
документ.

## Для кого

- Backend инженеры, которые дебажат flow-runtime.
- Инженеры поддержки, которым нужно быстро понять "где ломается".
- Junior разработчики, которым нужен не только код, но и объяснение логики.

## Как читать

1. Начни с `01-webhook-ingress-and-queue.md`.
2. Потом `02-worker-session-resolution.md`.
3. Затем `03-flow-engine-and-node-execution.md`.
4. Для полей БД и статусов открой `04-data-model-reference.md`.
5. Для разработки новых нод: `06-node-development-guide.md`.
6. Практические шаблоны нод: `07-node-cookbook.md`.
7. Матрица тестирования нод: `08-node-test-matrix.md`.
8. Стандарты config/keys/handles: `09-node-config-conventions.md`.
9. Текущий каталог зарегистрированных нод: `10-registered-nodes-catalog.md`.

## Быстрые ответы

- Где начинается обработка webhook: `App\Domains\Webhook\Http\WebhookController::__invoke()`.
- Где запускается основная доменная логика: `App\Domains\Webhook\Jobs\IncomingMessageJob::handle()`.
- Где решается "резюмить сессию или стартовать flow": `App\Domains\Flow\Orchestration\FlowOrchestrator::handle()`.
- Где исполняются ноды: `App\Domains\Flow\Services\FlowEngine::executeLoop()`.
- Где фактически отправляется сообщение провайдеру: `App\Domains\Flow\Services\FlowMessageSender::send()`.
