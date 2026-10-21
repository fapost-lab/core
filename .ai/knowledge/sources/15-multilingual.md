---
id: adr-15-multilingual
type: adr
status: accepted
domains: []
paths:
  - app/Domains/Flow/Services/LanguageResolver.php
  - app/Domains/Flow/Contracts/LanguageResolverInterface.php
  - "app/Domains/Flow/Translations/**"
  - app/Infrastructure/Flow/CachedContentTranslator.php
  - app/Domains/Tenancy/Settings/TenantSettings.php
  - "lang/**"
source: docs/platform/architecture/adr/15-multilingual.md
summary: Admin UI language vs content language, the resolution chain and tenant_translations
source_hash: 5f1788521cb956fceefb42bb752da1804ee359b2
reviewed_at: 2026-10-21
---
# ADR-15 — Мультиязычность

Linked source: [docs/platform/architecture/adr/15-multilingual.md](../../../docs/platform/architecture/adr/15-multilingual.md). The source owns its rules; this document only says when an agent
must read it.
