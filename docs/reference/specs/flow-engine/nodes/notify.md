# Node: `notify`

Sends a notification from a flow, in one of two modes: to **staff** (admin-panel users) or to **contacts**
(bot end-users).

**Type:** `notify` · **Version:** 1 · **Category:** `Contact`
**Class:** `app/Domains/Flow/Handlers/NotifyNodeHandler.php` · **Enums:** `NotifyMode` (`staff`, `contacts`),
`ContactNotifyTarget` (`tag`, `all`)

The node replaced the earlier `notify_staff` type. A missing or unknown `mode` falls back to `staff`. Both modes
have the single output `default`, and neither waits for a reply.

## Config

Staff mode:

```json
{
  "mode": "staff",
  "target": "assistant",
  "channel": "in_app",
  "message": "A customer asked for an operator: {{flow.reason}}"
}
```

Contacts mode:

```json
{
  "mode": "contacts",
  "assistant_id": "01HQ_assistant",
  "contact_target": "tag",
  "tags": ["vip"],
  "message": {"en": "Sale starts today", "uk": "Розпродаж починається сьогодні"}
}
```

| Field | Mode | Description |
|-------|------|-------------|
| `mode` | both | `staff` (default) or `contacts` |
| `message` | both | Required. A template; in contacts mode it may be a localized `{lang: text}` map, rendered recursively |
| `target` | staff | `StaffNotifyTarget`: `assistant` (all staff assigned to the session's assistant through `user_assistants`, default), `role`, `users` |
| `role` | staff, `target = role` | A `RoleEnum` value |
| `user_ids` | staff, `target = users` | Explicit staff user ids |
| `channel` | staff | `in_app` (default), `email`, or `all` (every registered staff transport); an unknown value falls back to in-app |
| `assistant_id` | contacts | Required: the assistant whose audience is addressed |
| `contact_target` | contacts | `tag` (default) or `all` |
| `tags` | contacts, `contact_target = tag` | Tag names; the audience is the union of contacts with any of them |

The builder UI is `NotifyConfig.vue`, with search dropdowns (`SearchSelect.vue`) backed by
`/builder/staff`, `/builder/assistants` and `/builder/tags`. Tags are assigned to a contact by hand in the
Filament `ContactResource` ("Manage tags" action).

## Output handles

- `default`: always.

## Behavior

### Staff mode (`executeStaff`)

1. If `system.staff_notified.{nodeId}` is already `true`, return `Executed` and do nothing (re-run safety).
2. Render `message`; an empty result throws `InvalidNodeConfigException`.
3. Dispatch `SendStaffNotificationJob` (queue `messaging.system`) with the target config, the resolved channel
   list and the message. Return `Executed`, setting the state marker `system.staff_notified.{nodeId}`.

The job re-establishes the tenant context, resolves recipients through `StaffRecipientResolver` (active staff only:
`is_active` and `status = active`), and sends through each selected transport of `StaffNotifierRegistry`
(`StaffNotifierInterface`: `in_app` Filament database notifications, `email` through `StaffNotificationMail`). A
failing channel is logged and does not fail the others. A new transport (for example a messenger bot) is
registered in `StaffServiceProvider`; the node does not change. A cache guard on `(tenant, session, node)`
(`Cache::add`, 24 h) stops a retried job from double-delivering.

The text is in the **admin-UI language**, not the content language: it does not go through `LanguageResolver`.

### Contacts mode (`executeContacts`)

1. If `system.contacts_notified.{nodeId}` is `true`, return `Executed` and do nothing.
2. An empty `assistant_id` or an empty message throws `InvalidNodeConfigException`.
3. Render the message against the running session (`{{flow.*}}`); a localized map is rendered per entry and the
   per-recipient language is left to the job.
4. Dispatch `App\Domains\Contact\Jobs\SendContactNotificationJob` (queue `messaging.broadcast`) and set the marker
   `system.contacts_notified.{nodeId}`.

`SendContactNotificationJob` takes a cache guard on `(tenant, session, node)`, restores the tenant context and
resolves the recipients: for `tag`, the union of `ContactTagRepository::contactIdsWithTag` intersected with the
assistant's active `ChannelContact` rows; for `all`, every contact with an active channel binding to the
assistant. It emits one `BroadcastSendJob` with an `OutboundMessage` per recipient. The content language is
per recipient through `ContentTranslatorInterface::resolveField` (`contact.language`, then
`assistant.default_language`, then the tenant fallback), unlike staff mode.

Platform limit: only a contact who already has a `channel_contact` for the assistant can be messaged (a first
contact cannot be initiated on Telegram or WhatsApp). Undeliverable tagged contacts are skipped and logged.
Send idempotency is a per-message key `{session}:{node}:notify:{contactId}` in `MessageSender`, which also
rate-limits per `(channel, chat)`.

## Tests

`tests/Feature/Domains/Flow/NotifyNodeHandlerTest.php` (both modes: dispatch and marker, idempotency, missing
assistant, empty message), `tests/Feature/Domains/Staff/StaffNotificationTest.php`,
`tests/Feature/Domains/Contact/SendContactNotificationJobTest.php` (tag audience limited to reachable contacts,
`all`, retry idempotency, per-recipient language).

## Not built

- Backpressure by transactional-queue length in `BroadcastSendJob`: it relies on the `MessageSender` rate limit
  and the low-priority queue. This gap is wider than this node.
- Staff-to-messenger identity (delivering staff notifications to a staff member's Telegram or WhatsApp) and
  addressing staff by tag: there is no staff-tag model.
- A separate output for "no recipients": an empty recipient set is a logged no-op.
