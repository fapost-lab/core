#!/usr/bin/env sh

set -eu

previous_ref="${1:-}"
current_ref="${2:-}"

if [ -z "$previous_ref" ] || [ -z "$current_ref" ]; then
    exit 0
fi

if ! git rev-parse --verify "${previous_ref}^{commit}" >/dev/null 2>&1; then
    exit 0
fi

if ! git rev-parse --verify "${current_ref}^{commit}" >/dev/null 2>&1; then
    exit 0
fi

landlord_changed="$(git diff --name-only --diff-filter=ACDMRT "$previous_ref" "$current_ref" -- database/migrations/landlord)"
tenant_changed="$(git diff --name-only --diff-filter=ACDMRT "$previous_ref" "$current_ref" -- database/migrations/tenant)"

if [ -z "$landlord_changed" ] && [ -z "$tenant_changed" ]; then
    exit 0
fi

printf '%s\n' ""
printf '%s\n' "Migration files changed after git update."

if [ -n "$landlord_changed" ]; then
    printf '%s\n' "Warning: landlord migrations changed."
fi

if [ -n "$tenant_changed" ]; then
    printf '%s\n' "Warning: tenant migrations changed."
fi

printf '%s\n' "Review pending migrations explicitly with: php artisan migrate:smart"
