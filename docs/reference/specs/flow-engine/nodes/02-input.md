# Node · `input`

Prompts the contact, parks the session until a matching reply arrives, validates it and
stores the result — or ingests media attachments through the media pipeline.

**Type:** `input`
**Version:** 1
**Category:** `Core`
**Handler:** `App\Domains\Flow\Handlers\InputNodeHandler`

## Config

```json
{
  "prompt": "What's your name?",
  "expected_type": "text",
  "variable": {"name": "first_name", "storage": "contact", "type": "text", "group": null},
  "validation": {"min_length": 2, "max_length": 50},
  "retry_limit": 3,
  "on_invalid_message": "Please try again"
}
```

**Fields actually read by `execute()`:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `expected_type` | enum `InputExpectedType` | no | Default `Text`. Drives accepted input, parsing and whether a keyboard/native button is shown before parking. |
| `variable` | object `{name, storage, type, group}` | no | Preferred save target, resolved via `Variable::tryFromArray()`. |
| `save_to` | string | no | Legacy save target, used only when `variable` is absent (see Legacy config). |
| `prompt` | string/translatable | no | Sent before parking, unless already sent (idempotency gate) or (for non-select prompts) blank after translation. |
| `buttons` | list of `{id, label, value, type}` | no | Used to build the inline keyboard for `select`/`confirm` prompts. |
| `request_button_label` | string/translatable | no | Label for the native share button (`contact`/`location`); falls back to a type-based default when blank. |
| `validation` | array | no | Passed straight through to `InputValidatorInterface::validate()` as `$rules`. For `expected_type = phone`, the handler adds `countries` itself (see below). |
| `retry_limit` | int | no | Default `3` (`DEFAULT_RETRY_LIMIT`). Clamped to `≥ 0`. |
| `on_invalid_message` | string/translatable | no | Sent (if non-empty after translation) on each invalid attempt that still has retry budget. |

`configSchema()` only declares `save_to` (Storage section) and `expected_type` / `retry_limit` / `on_invalid_message` (Validation section) — `prompt`, `buttons`, `request_button_label`, `validation` and `variable` are read from config at runtime but are not part of the declared schema: the builder edits this node through its bespoke override `resources/js/builder/components/editor/config/overrides/InputConfig.vue`, which writes them.

## `expected_type` values (`InputExpectedType`)

`text`, `number`, `email`, `phone`, `date` (textual — parsed/validated as a string format), `select`, `confirm` (render an inline keyboard and wait for a callback), `contact`, `location` (platform-native share via a reply-keyboard button), `file`, `image`, `document`, `video`, `voice`, `audio` (media, routed through the ingest pipeline).

## Output handles

- `default` — value accepted and stored (`emitSuccess()`), or media successfully ingested (`handleMediaInput()`).
- `invalid` — validation failed and the retry budget (`retry_limit`) is exhausted.

There is no dedicated timeout/media-rejected handle: a still-waiting node without a usable answer just stays `waiting`.

## Behavior

1. Resolve `expected_type` and the target `variable`.
2. **Media types** (`isMedia()`): send the plain prompt if not sent yet (no keyboard); with an incoming message, call `handleMediaInput()`.
3. **Platform-native types** (`isPlatformNative()`: `contact`, `location`): send the native-share prompt if not sent yet (`sendNativeButtonPromptIfNeeded()`).
4. **Everything else**: send the prompt if not sent yet — with a keyboard when `isSelect()` (`select`/`confirm`), otherwise plain text (`sendPromptIfNeeded()`).
5. A non-null prompt-send result is a `Waiting` result and is returned as-is.
6. No incoming message yet → `waiting()`.
7. Build `$rules` from `config.validation`; for `expected_type = phone`, `$rules['countries']` is populated from `resolveAssistantCountries()` — the running session's assistant's `available_countries` column.
8. `InputValidatorInterface::validate($expectedType, $incoming, $rules, $nodeConfig)`. Valid → `emitSuccess()`; invalid → `handleInvalid()`.

### Prompt sending (`sendPromptIfNeeded()`)

Gated by `system.sent_messages.{nodeId}` (skips on repeat passes). A select/confirm prompt is always sent even when its text is blank (the keyboard is the only way to answer); a plain prompt is skipped when its resolved text is empty. Payload is `text_with_keyboard` (`keyboard_mode: inline`, buttons from `resolveButtons()`) or plain `text`. Records `system.sent_messages.{nodeId}` and returns `Waiting`.

### Native-button prompt (`sendNativeButtonPromptIfNeeded()`)

Same `system.sent_messages` gate. Sends a `text_with_keyboard` payload with `keyboard_mode: reply` and a single button (`id: 'native-button'`, `special: request_contact|request_location`). Label: `request_button_label` if non-blank, else `"📱 Share contact"` / `"📍 Share location"` / `"Share"`.

### Validation outcome

- **Success** (`emitSuccess()`): `persistValue()` writes the (possibly parsed) value to the resolved variable; if the per-node retry counter (`system.input.{nodeId}.retry_count`) was non-zero it is reset to `null`. Result: `executed(sourceHandle: 'default')`, metadata `received`.
- **Invalid** (`handleInvalid()`): increments `system.input.{nodeId}.retry_count`. If the new count exceeds `retry_limit`, result is `executed(sourceHandle: 'invalid')` with the counter reset to `null`, metadata `error_key` and `retry_count` (pre-increment value). Otherwise sends `on_invalid_message` (if configured and non-blank) and returns `waiting()` with the incremented counter, metadata `error_key`/`retry_count`.

### Media input (`handleMediaInput()`)

No incoming message or no attachments → `waiting()`. Attachments are filtered by `expected_type` (`file` accepts everything; `image`/`document`/`video` match their own kind; `voice`/`audio` both match kind `audio`); none accepted → `waiting()`. The delivering channel is resolved (`resolveChannel()` — most recently active `ChannelContact` for the session's assistant; throws `RuntimeException` if none). Each accepted attachment is ingested through `MediaIngestorInterface::ingestFromChannel()` (ingest failures are wrapped in a `RuntimeException` and propagate — not converted to an `invalid`/`error` handle) into a descriptor `{media_file_id, name, kind, mime_type, size, provider_file_id}`. An `array`-typed variable appends each descriptor as its own element (`persistElements()`); any other variable stores the whole list (`persistValue()`). Result: `executed(sourceHandle: 'default')`, metadata `received` = list of ingested `media_file_id`s.

### Persisting a value (`persistValue()` / `persistElements()`)

Contact storage writes immediately through `ContactWriterInterface` and contributes no `stateChanges`. Session storage: an `array`-typed variable appends into the existing collection at its resolved path; any other type replaces the value at that path — both returned via `stateChanges`.

## State written

- `system.sent_messages.{nodeId}` — prompt send idempotency marker.
- `system.input.{nodeId}.retry_count` — invalid-attempt counter; cleared (`null`) on success or once the retry limit is exhausted.
- The resolved `variable` target — session storage only (contact storage is written immediately, outside `stateChanges`).

## Side effects

- `MessageSenderInterface::send()` for the prompt, the native-button prompt, and the `on_invalid_message` hint.
- `MediaIngestorInterface::ingestFromChannel()` for media inputs.
- Reads: `FlowSession`, `Assistant` (phone-validation candidate countries), `ChannelContact`/`Channel` (media channel resolution).

## Legacy config

`resolveVariable()` prefers `config.variable`; when absent, it falls back to the legacy `config.save_to` string path via `VariableResolverInterface::fromLegacyPath()`.

## Related

- [01-send-message.md](01-send-message.md) — prompt payload shape reused for `select`/`confirm`.
- [03-branch.md](03-branch.md) — often follows `input` to route on the captured value.
