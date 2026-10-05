---
id: convention-multilingual
type: convention
status: active
domains: []
paths:
  - "app/Domains/Flow/**"
  - "app/Domains/Messaging/**"
  - "app/Filament/**"
  - "lang/**"
  - "app/Domains/Assistant/**"
summary: Admin UI language and content language stay separate; content resolves via LanguageResolverInterface, and a button/select value is never translated
reviewed_at: 2026-10-05
---
# Multilingual layers

## Practice

Two language layers are kept apart:

- **Admin UI language** — Laravel lang files, Filament/backend validation, staff UI.
- **Content language** — runtime assistant messages to the end user.

Runtime language resolution goes through `LanguageResolverInterface` and the content
translator chain; nothing else picks the content language. Do not put user-facing,
bot-facing literals directly into handlers or senders — such strings are system
translation keys or flow content.

A button/select `value` is language-agnostic and never translated; only the
label/content is.

Enforcement is review only: no PHPat rule or test backs this, so a mixed layer or a
literal in a handler is caught by a human reviewer, not CI.

## Example

A flow button node stores its option as `{"label": {"en": "Confirm", "es":
"Confirmar"}, "value": "confirm"}`. The handler compares against `value`, which
stays `"confirm"` in every locale; only `label` goes through the content translator
chain. A validation message shown to a Filament staff user, by contrast, comes from
`lang/{locale}` (only `lang/en` exists) and never touches `LanguageResolverInterface` — it belongs
to the admin UI layer, not the content one.

## Rationale

The two layers serve different audiences with different owners: staff UI copy is a
framework/product concern, content is tenant- and contact-specific runtime data.
Collapsing them would let an admin-locale change leak into what a contact reads, or
force runtime content through Laravel's translation files, which cannot express a
per-tenant, per-contact fallback chain. Centralizing content resolution in
`LanguageResolverInterface` keeps that fallback chain in one place instead of being
reimplemented per handler or sender. See ADR-15 (multilingual) for the full
resolution chain and storage model; `.ai/knowledge/RULES.md` records this convention
as review-only.
