# AI_CONTEXT

## Current Task Mode

Always isolate one task.

Do not mix:

- tenancy
- payments
- queue orchestration
- flow engine

## Current Architectural Focus

Sensitive zones:

- scoped bindings
- activation runtime
- tenant lifecycle
- flow registry

## Review Goal

Look only for:

- critical flaws
- hidden coupling
- lifecycle risks

Ignore:

- cosmetic improvements
- style-only changes

## Diff Rule

Never send full diff unless necessary.

Send:

- summary
- changed boundary
- critical fragment

## AI Pipeline

Cursor:
draft generation

Claude:
critical review

ChatGPT:
second opinion

## Prompt Rule

Use prompts like:

- critical flaws only
- no cosmetic review
- architectural risks only
