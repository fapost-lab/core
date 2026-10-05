# 08 · Contact card — attribute groups as sections

**Layer:** Filament admin (PHP), assistant panel
**Depends on:** 05 (the resolver writes `contact.attributes.{group}.{name}`)

## Purpose

The read-only contact card in the assistant panel (Contacts → a contact) renders `attributes` as
sections instead of one raw JSON blob. Implemented.

## Layout

- **Header** — identity: platform, external id, language, optional `meta.username`, the contact's tags
  and groups (contact groups, a separate concept from attribute groups).
- **Profile** — root-level scalar attributes; shown only when there are any.
- **One collapsible section per attribute group** (a nested object), titled `<group> (N fields)`.
- **From platform** — collapsed section with the `meta` JSON; shown only when `meta` is not empty.
- Temporary (`session`) variables never appear: they live in the flow session, not on the contact.

Profile and group sections start expanded when the contact has at most 3 groups
(`GROUP_EXPAND_THRESHOLD`), collapsed otherwise.

## Value rendering

Each field is a `TextEntry` with a formatted state. The declared `VariableType` comes from the tenant
schema registry (`VariableSchemaRegistryInterface::get('contact', $group, $name)`):
`number` is number-formatted, `date` is localised (`d M Y`), `json` is pretty-printed, `confirm`
renders Yes/No. Fields without a registry entry (legacy) or of other types render as plain strings.
The view is read-only.

## Files

- `app/Filament/Assistant/Resources/Contacts/ContactResource.php`
- `app/Filament/Assistant/Resources/Contacts/Schemas/ContactInfolistSchema.php` — section rendering
- `app/Filament/Assistant/Resources/Contacts/Pages/ViewContact.php`
- `lang/{en,ru,uk}/contact.php` — section titles, field labels, Yes/No values

## Out of scope

- Inline editing of attributes (needs its own permission UX).
- Bulk export by group.
- Drag-reordering of sections.
