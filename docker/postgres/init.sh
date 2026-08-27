#!/bin/bash
# Database initialisation for FaPost Core.
#
# A shell script rather than plain SQL because the database and user names come
# from the environment, and psql does not receive them as variables on its own.
#
# Runs once, when the postgres volume is first created. Editing it later has no
# effect on an existing volume — apply such changes by hand with psql.
set -euo pipefail

psql -v ON_ERROR_STOP=1 --username "${POSTGRES_USER}" --dbname "${POSTGRES_DB}" <<-EOSQL
    -- Tenant isolation is schema-per-tenant, and schemas are created at runtime
    -- when a tenant is provisioned. Inside this container the application user
    -- already owns the database and holds this implicitly, so the grant is a
    -- no-op here — it is stated because the same requirement is easy to miss on
    -- a managed PostgreSQL, where a clean install succeeds and then fails on the
    -- first tenant with a permission error that points nowhere useful.
    GRANT CREATE ON DATABASE "${POSTGRES_DB}" TO "${POSTGRES_USER}";

    -- Landlord tables live in the public schema and are created by migrations.
    GRANT ALL ON SCHEMA public TO "${POSTGRES_USER}";
EOSQL

echo "init: grants applied to ${POSTGRES_DB} for ${POSTGRES_USER}"
