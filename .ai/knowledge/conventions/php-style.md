---
id: convention-php-style
type: convention
status: active
domains: []
paths:
  - "**/*.php"
summary: Strict types, final classes, PHPDoc and Pint as the mechanical half of PHP style
reviewed_at: 2026-10-05
---
# PHP style and Laravel conventions

## Practice

- Follow the patterns of neighbouring files before introducing a new shape.
- Every `.php` file opens with `declare(strict_types=1);`.
- Classes are `final` by default; constructors use PHP 8 constructor property
  promotion, and every method declares an explicit return type.
- Enum cases are written in TitleCase (`Monthly`, not `MONTHLY` or `monthly`).
- Reach for a PHPDoc block to convey meaning, an array shape or a generic
  (`Collection<int, Model>`); inline comments are reserved for genuinely complex
  logic, not for restating what the code already says.
- Prefer `php artisan make:* --no-interaction` for new Laravel artifacts (models,
  controllers, jobs, ...) over hand-rolled boilerplate.
- Do not add a Composer or npm dependency without agreement.
- Cover every code change with a minimal relevant test and run it.
- After touching PHP, run `vendor/bin/pint --dirty --format agent` before finishing
  — it fixes formatting in place, it is not a check to satisfy separately.
- Create documentation files only when the user explicitly asks.

## Example

`app/Domains/Assistant/Services/AssistantSwitchListService.php`: `declare(strict_types=1)`
at the top, a `final class`, a promoted readonly `TenantContextInterface` constructor
parameter, and an explicit `Collection<int, Assistant>` return type documented in a
PHPDoc block above `forUser()`.

## Rationale

Pint enforces the mechanical half of this list. `pint.json` builds on the PSR-12
preset and turns on `declare_strict_types` and `final_class` among its rules, and
`vendor/bin/pint --dirty --format agent` applies them to every changed file — so
`declare(strict_types=1)` and `final` are not stylistic preferences, they fail
formatting if skipped. `pint.json` also excludes `database`, `tests`, `routes`,
`config`, `resources`, `bootstrap`, `storage`, `public`, `docs`, `drafts`, `packages`,
`tools` and `vendor`, so Pint only actually checks `app/` and sibling top-level PHP;
everything outside that, and everything Pint cannot express — constructor
promotion, explicit return types, PHPDoc quality, enum casing, dependency
approval, test coverage, doc-file restraint — holds on review only. Shared style
is what lets an agent trust a neighbouring file as the pattern to copy, in a
codebase with many domains and many contributors.
