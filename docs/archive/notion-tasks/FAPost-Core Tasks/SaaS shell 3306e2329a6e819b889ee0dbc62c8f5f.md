# SaaS shell

> Архив Notion. Актуальная документация: [[02-saas-shell]]


Depends on: 04
Domain: Tenancy
Phase: 5 — Self-hosted & SaaS
Sprint: 10
Status: К реализации
Task №: 27

## Состав

- `plans`, `subscriptions` (внутренний учёт, без Stripe)
- `feature_flags`
- Tenant onboarding pipeline
- Landlord Filament panel
- `tenant_modules` activation

## Примечания

> ⚠ Stripe исключён — юридически недоступен.
>