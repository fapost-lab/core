# Node: `notify`

Sends a notification from a flow, in one of two modes: to **staff** (admin-panel users) or to **contacts**
(bot end-users). The contacts mode is **retired**: a node already published in it still runs, as a broadcast started
through Broadcasting, but publishing one that is new or changed is refused. A message to many contacts is a
`send_message` to tags or a segment (`adr-20261125-notify-contacts-retired-through-broadcasting`).

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

Contacts mode (retired; only as already published):

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
| `assistant_id` | contacts | Required: the assistant whose audience is addressed (the node's own, which may differ from the flow's) |
| `contact_target` | contacts | `tag` (default) or `all` |
| `tags` | contacts, `contact_target = tag` | Tag names; the audience is the union of contacts with any of them |

The builder UI is `NotifyConfig.vue`. It offers the contacts mode only to a node opened in it, and shows such a node
as retired; the config schema still lists the mode so that node renders. It has search dropdowns (`SearchSelect.vue`) backed by
`/builder/staff`, `/builder/assistants` and `/builder/tags`. Tags are assigned to a contact by hand on the
console's contact card ("Edit tags").

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
(`StaffNotifierInterface`: `in_app` Laravel database notifications (`StaffMessageNotification`, in the data shape Filament's database notifications had), `email` through `StaffNotificationMail`). A
failing channel is logged and does not fail the others. A new transport (for example a messenger bot) is
registered in `StaffServiceProvider`; the node does not change. A cache guard on `(tenant, session, node)`
(`Cache::add`, 24 h) stops a retried job from double-delivering.

The text is in the **admin-UI language**, not the content language: it does not go through `LanguageResolver`.

### Contacts mode (`executeContacts`, retired)

1. If `system.contacts_notified.{nodeId}` is `true`, return `Executed` and do nothing: a session that already passed the
   node (also one in flight when the mode moved to Broadcasting) does not send again.
2. An empty `assistant_id` throws `InvalidNodeConfigException`.
3. A `message` that reads a contact (`{{contact.*}}` or `{{module.*}}` in any language) sends nothing: in a conversation
   it would put the run's contact's data in front of the whole audience. Outcome `contact_expression_refused`.
4. Render every language of the message against the running session (`{{flow.*}}`); an empty result throws
   `InvalidNodeConfigException`. A plain string is the base language's text.
5. Start a broadcast through `AudienceBroadcasterInterface::start()` (Broadcasting's `AutomationBroadcasts`): the node's
   `assistant_id`, audience `tags` (`contact_target = tag`) or `all` (only this node can name everyone), the name
   "<flow name> — Y-m-d H:i", `flow_id`/`flow_session_id`/`flow_node_id`, `origin_key` = sha256 of
   `idempotencyKey:nodeId` (a retried step gets the same broadcast back, `replayed`), `chain_depth` = the run's + 1.
   The row is `origin = automation`, shown and cancellable on the console.
6. Every outcome sets the marker and leaves through `default`; the trace metadata carries `broadcast_outcome`
   (`started`, `replayed`, `broadcast_running`, `audience_missing`, `message_empty`, `broadcast_failure`,
   `contact_expression_refused`), with `broadcast_id` and `recipient_count` when one started. Anything but a started
   or replayed broadcast logs a warning (`flow.notify_contacts_not_sent`, `flow.notify_contacts_refused`).

Compared with the earlier fan-out job, the node now has Broadcasting's limits: while its previous broadcast runs, a
pass sends nothing (`broadcast_running`); a message without base-language text sends nothing (`message_empty`); and
the send goes through Broadcasting's backpressure and the `outbound_messages` limit, which cancels the whole broadcast.

The earlier fan-out jobs (`SendContactNotificationJob`, `BroadcastSendJob`) were kept in 0.25 to deliver what an older
release queued, and removed after it; an installation upgrades through 0.25 and drains `messaging.broadcast` there.

### Authoring (retired)

`NotifyNodeHandler::validate()` warns about a contacts node (`notify_contacts_deprecated`) and about one whose message
reads a contact (`notify_contacts_contact_expression`). `PublishFlowService` refuses the publish of a flow's draft or a
template's draft that adds a contacts node or changes one against the published version (`notify_contacts_retired`,
`FlowPublishChanges::changedContactNotifications()`: mode, assistant, audience, tags, message, after template
expansion). A template republishing its hosts is not checked: an unchanged legacy node in a host does not stop it.

## Tests

`tests/Feature/Domains/Flow/NotifyNodeHandlerTest.php` (staff dispatch and marker; the contacts request, marker,
refusals and failure on `default`, contact placeholders, validation warnings),
`NotifyContactsBroadcastTest.php` (the broadcast row, `all`, replay and `broadcast_running`),
`NotifyContactsPublishTest.php` (new, changed, unchanged, template), `tests/Feature/Domains/Staff/StaffNotificationTest.php`.

## Not built

- Staff-to-messenger identity (delivering staff notifications to a staff member's Telegram or WhatsApp) and
  addressing staff by tag: there is no staff-tag model.
