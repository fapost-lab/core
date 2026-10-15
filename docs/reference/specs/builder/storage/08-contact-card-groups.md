# 08 · Contact card — attribute groups as sections

**Layer:** console (PHP `ContactCard` + Inertia page), an assistant's console
**Depends on:** 05 (the resolver writes `contact.attributes.{group}.{name}`)

## Purpose

The contact card in an assistant's console (Contacts → a contact) renders `attributes` as
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

Each field carries a value already formatted as text. The declared `VariableType` comes from the tenant
schema registry (`VariableSchemaRegistryInterface::get('contact', $group, $name)`):
`number` is number-formatted, `date` is localised (`d M Y`), `json` is pretty-printed, `confirm`
renders Yes/No. Fields without a registry entry (legacy) or of other types render as plain strings.
The attributes are read-only; tags and groups are edited from the card.

The card is built by `App\Domains\Contact\Services\ContactCard` and drawn by `Console/Contacts/Show`. It sends properties as `{key, value}` lists, because a JSON object reorders numeric
keys and Postgres `jsonb` reorders all of them. Keys are sorted alphabetically (natural, case-insensitive) in the
profile, the groups, the fields of a group and the platform data. A list at the root of `attributes` is one profile
field (its items joined with `, `), and an empty array is an empty field. `contact.values.yes|no` and the
`ru`/`uk` plural forms of `contact.sections.group_fields` exist since the console was built.

## Files

- `app/Domains/Contact/Services/ContactCard.php` — sections and value formatting
- `app/Http/Controllers/Console/ContactController.php`
- `resources/js/pages/Console/Contacts/Show.vue`
- `lang/{en,ru,uk}/contact.php` — section titles, field labels, Yes/No values

## Out of scope

- Inline editing of attributes (needs its own permission UX).
- Bulk export by group.
- Drag-reordering of sections.
