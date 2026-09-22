# Node · `send_message`

Sends content to the contact through the active channel, with optional inline/reply
keyboards and dynamic keyboards built from state data.

**Type:** `send_message`
**Version:** 1
**Category:** `Core`
**Handler:** `App\Domains\Flow\Handlers\SendMessageNodeHandler`

## Config

```json
{
  "content_type": "text_with_keyboard",
  "text": "Choose one",
  "media_file_id": null,
  "caption": null,
  "keyboard_mode": "inline",
  "buttons": [
    {"id": "8f14e...-uuid", "label": "Yes", "value": "yes"},
    {"id": "3a01c...-uuid", "label": "No", "value": "no"}
  ],
  "dynamic_buttons": null,
  "save_to_variable": {"name": "answer", "storage": "session", "type": "text", "group": null},
  "timeout_seconds": 60,
  "remove_keyboard_after_press": true
}
```

**Fields** (schema in `configSchema()`):

| Field | Type | Required | Notes |
|---|---|---|---|
| `content_type` | enum `SendMessageContentType` | yes | `text`, `text_with_keyboard`, `image`, `document`, `video`, `voice`. Default `text`. |
| `text` | string/translatable | conditional | Required (key must be present) for `text` and `text_with_keyboard`. |
| `media_file_id` | string | conditional | Required when `content_type` is `image`, `document`, `video` or `voice` (`SendMessageContentType::requiresMediaUrl()`). |
| `caption` | string/translatable | no | Shown for `image`, `document`, `video`. |
| `keyboard_mode` | enum `KeyboardMode` (`Fapost\Foundation\Flow\Enums\KeyboardMode`) | conditional | `inline` or `reply`. Required when `content_type = text_with_keyboard`. Default `inline`. |
| `buttons` | list of `{id, label, value}` | conditional | Each `id` must be a valid UUID (`Ramsey\Uuid\Uuid::isValid`). Required (or `dynamic_buttons`) when `content_type = text_with_keyboard`. |
| `dynamic_buttons` | object `{source, max_per_row, save_item_to}` | no | `source` is a non-empty state path (`StatePickerField`, namespaces `flow`/`contact`); `max_per_row` defaults to 2 (min 1); `save_item_to` is an optional `Variable` shape. |
| `save_to` | string | no | Legacy scalar target path for a pressed static button's `value`. |
| `save_to_variable` | object `{name, type, storage, group}` | no | Preferred target for a pressed static button's `value`; wins over `save_to`. |
| `timeout_seconds` | int ≥ 1 | no | When set, schedules a timeout resume for inline keyboards. |
| `remove_keyboard_after_press` | bool | no | Default `true`. |

## Output handles

- `error` — `MessageSenderInterface::send()` threw; metadata carries `error` and `error_type: 'send_failure'`.
- `no_response` — an inline-keyboard prompt timed out without a button press.
- The pressed button's handle when `content_type = text_with_keyboard` and `keyboard_mode = inline`:
  - static keyboard — the button's own UUID `id` string;
  - dynamic keyboard (`dynamic_buttons` set) — always `default`.
- `default` — normal completion for every non-inline-keyboard payload (`NodeExecutionResult::executed()` default handle).

## Behavior

1. Idempotency gate: `state[system.sent_messages][nodeId]` records whether this node already sent its message.
2. If the message was already sent, the content is `text_with_keyboard` + `keyboard_mode = inline`, and an incoming message is present, the handler resumes waiting for the button press (`resumeInlineKeyboard()`), reloading generated buttons from `system.send_message.dynamic_buttons.{nodeId}` when `dynamic_buttons` was configured.
3. If already sent and no incoming to resume with: `NodeExecutionResult::waiting()` for inline keyboards, `NodeExecutionResult::executed()` otherwise.
4. On the first pass: if `dynamic_buttons` is set, buttons are generated from the state collection at `source` (`generateDynamicButtons()` — see below); the payload's `text`/`caption`/button labels are resolved through `ContentTranslatorInterface::resolveField()` then rendered through `TemplateRenderer`, merging session state with a small `contact` context (`id`, `language`, `name`/`first_name`, `username`, `channel`).
5. `MessageSenderInterface::send(tenantId, contactId, sessionId, payload)` is called. A thrown exception routes to `error`.
6. `system.sent_messages.{nodeId}` is set to the returned external message id.
7. For `text_with_keyboard` + `inline`:
   - if dynamic, the generated buttons are persisted to `system.send_message.dynamic_buttons.{nodeId}` so a resume doesn't need to regenerate them;
   - if `timeout_seconds` is set, `system.send_message.timeout.{nodeId}.at` is written and the timeout is scheduled through `SendMessageTimeoutSchedulerInterface` (`ResumeTimedOutSendMessageNodeJob` on `flow.execution`, delayed to `$timeoutAt`; not queued at all on a `sync` queue, where the timeout never fires);
   - if `remove_keyboard_after_press` is `false` and a message id was returned, each button is registered with `PersistentButtonRegistryInterface::register()` (best-effort — swallows any `Throwable`) so a press can be routed after the session has ended;
   - result is `NodeExecutionResult::waiting()` with metadata `external_message_id`.
8. For every other content type, result is `NodeExecutionResult::executed()` with metadata `external_message_id`.

### Dynamic button generation (`generateDynamicButtons()`)

Iterates the array at `dynamic_buttons.source`; items without a non-empty string `label` are skipped. Each button gets a deterministic id — `Uuid::uuid5(Uuid::NAMESPACE_OID, "{nodeId}:{index}")` — so ids stay stable across job retries, a `row` computed from `floor(index / max_per_row)`, and the full source `item` attached for later use by `save_item_to`.

### Resuming an inline keyboard (`resumeInlineKeyboard()`)

1. If `system.send_message.response.{nodeId}.handle` is already set and its stored `update_id` matches the current incoming `updateId`, the handler replays that handle without re-processing (`executed()` idempotency guard against duplicate deliveries of the same update).
2. If the incoming payload carries `send_message_timeout: true` (set by the timeout job's resume), the keyboard is removed (when `remove_keyboard_after_press`) and the node exits through `no_response`, recording the response marker.
3. Otherwise the incoming text is decoded as Telegram `callback_data` via `CallbackDataCodec::decode()`. A `session_id` mismatch (stale/foreign callback) triggers a best-effort hint message (`sendWaitingHint()`, itself swallowing `Throwable`) and `waiting()`.
4. A missing/empty `button_id` in the decoded payload also yields `waiting()`.
5. When a configured button matches the pressed id: the keyboard is removed if configured; the response marker (`handle`, `update_id`) is recorded; for a dynamic keyboard the full matched item is written to `save_item_to` (contact storage writes immediately via `ContactWriterInterface`, session storage goes into `stateChanges`), for a static keyboard the button's scalar `value` is written to the resolved save target (`save_to_variable` preferred, else legacy `save_to`). Result is `executed(sourceHandle: $handle, stateChanges)`.
6. No matching button → `waiting()`.

## State written

- `system.sent_messages.{nodeId}` — external message id once sent (send idempotency).
- `system.send_message.dynamic_buttons.{nodeId}` — generated dynamic buttons (dynamic keyboards only).
- `system.send_message.timeout.{nodeId}.at` — ISO-8601 timeout instant (when `timeout_seconds` set).
- `system.send_message.response.{nodeId}.handle` / `.update_id` — recorded once a button press or timeout has been processed (replay guard).
- The configured `save_to`/`save_to_variable`/`save_item_to` target, for a pressed button (session storage only; contact storage is written immediately).

## Side effects

- `MessageSenderInterface::send()` — the outgoing message, and (best-effort) the "please press a button" hint sent when text arrives while waiting.
- `SendMessageTimeoutSchedulerInterface::schedule()` when `timeout_seconds` is configured (a delayed `ResumeTimedOutSendMessageNodeJob`; skipped on a `sync` queue).
- `InlineKeyboardEditorInterface::removeKeyboard()` when `remove_keyboard_after_press` is true and a press/timeout is processed.
- `PersistentButtonRegistryInterface::register()` (best-effort) when `remove_keyboard_after_press` is `false`; skipped silently when `system.flow_definition_id` is absent from state.

## Idempotency / replay

Sending is gated by `system.sent_messages.{nodeId}`, so a re-executed node never resends. Inline-keyboard resumption is gated by the stored `{handle, update_id}` pair, so replaying the same webhook update yields the same handle without re-applying the button's write.

## Legacy config

- `media_url` / `media_path`: if present, the handler tries to recover `media_file_id` from a `/media/files/{uuid}` URL path via regex, then discards both legacy keys.
- `save_to` (plain state path string): used only when `save_to_variable` is absent, resolved through `VariableResolverInterface::fromLegacyPath()`.

## Related

- [02-input.md](02-input.md) — shares the same keyboard/prompt payload shape.
- [../../../../platform/runtime/flow/10-registered-nodes-catalog.md](../../../../platform/runtime/flow/10-registered-nodes-catalog.md) — full handler catalog.
