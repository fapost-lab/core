---
id: convention-multilingual
type: convention
status: active
domains: []
paths:
  - "app/Domains/Flow/**"
  - "app/Domains/Messaging/**"
  - "lang/**"
  - "app/Domains/Assistant/**"
  - app/Http/Middleware/SetLocale.php
  - app/Http/Controllers/Console/LocaleController.php
  - app/Http/Middleware/HandleInertiaRequests.php
summary: "Admin UI language (per person: SetLocale, the locale cookie, users.locale, shared Inertia translations) and content language stay separate; content resolves via LanguageResolverInterface, and a button/select value is never translated"
reviewed_at: 2026-10-26
---
# Multilingual layers

## Practice

Two language layers are kept apart:

- **Admin UI language** — what staff read: the console, the builder, validation messages, and
  mail and notifications to staff. Laravel lang files (`lang/{en,ru,uk}`).
- **Content language** — runtime assistant messages to the end user.

Runtime language resolution goes through `LanguageResolverInterface` and the content
translator chain; nothing else picks the content language. Do not put user-facing,
bot-facing literals directly into handlers or senders — such strings are system
translation keys or flow content.

A button/select `value` is language-agnostic and never translated; only the
label/content is.

A tenant's content base language is always one of its available languages: flows are
written in it, so a list of languages that leaves it out offers a choice nobody can use.
`TenantSettingsEditor::withBase()` adds it on every save (the settings screen and the setup
wizard), and the forms add it when a base is chosen. A screen that offers a choice of the
tenant's languages reads them through `withBase()` too, since settings stored before
2026-10-26 may lack it.

The admin UI language is one per person, resolved by `SetLocale` on every web request: a
`?lang=` override, then the session, then a long-lived cookie, then the browser's
`Accept-Language` on the first visit only, then `app.locale`; only
`SetLocale::SUPPORTED_LOCALES` are accepted. The cookie keeps its historical name
(`filament_language_switcher_locale`, `SetLocale::LOCALE_COOKIE`): renaming it would reset
everyone's choice. The shell's language switcher posts to `console.locale.update`
(`LocaleController`), which writes the session, the cookie and `users.locale`; mail and
notifications read `User::preferredLocale()`, because a queued job has no session. Pages get
their strings as shared Inertia props from `HandleInertiaRequests` — `translations.<namespace>`
(`builder`, `auth`, and on console routes `console`), never whole files another page needs —
and there is no front-end i18n library. A key added for the console exists in every supported
locale.

Enforcement is review only: no PHPat rule or test backs this, so a mixed layer or a
literal in a handler is caught by a human reviewer, not CI.

## Example

A flow button node stores its option as `{"label": {"en": "Confirm", "es":
"Confirmar"}, "value": "confirm"}`. The handler compares against `value`, which
stays `"confirm"` in every locale; only `label` goes through the content translator
chain. A validation message shown to a staff user in the console, by contrast, comes from
`lang/{locale}` in the locale `SetLocale` resolved for that person, and never touches
`LanguageResolverInterface` — it belongs to the admin UI layer, not the content one.

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
