---
paths:
  - 'tests/Architecture/**'
---

# Architecture

## PHPat rules only run if registered and selectors must not pass regex=true
Two silent-no-op traps that made every architecture rule pass vacuously until 2026-08-28:

1. PHPat discovers rules ONLY via services tagged `phpat.test` in phpstan.neon (see PHPat\Test\TestExtractor). A class in tests/Architecture that is not listed there never runs and reports success forever. `tests/Unit/Architecture/RuleRegistrationTest.php` now fails when the two lists drift.

2. The second argument of `Selector::inNamespace()` / `classname()` / `withFilepath()` is `$regex`, NOT "include sub-namespaces". Passing `true` with a plain FQCN makes it an undelimited pattern that matches nothing. Prefix matching already covers sub-namespaces, so write `Selector::inNamespace('App\Domains\Flow')`. Only pass `true` with real delimiters, e.g. `'#^App\\Domains\\.*\\Models$#'`.

After fixing a rule, always confirm it actually fires (temporarily widen it to a known violator) before trusting a green run.
