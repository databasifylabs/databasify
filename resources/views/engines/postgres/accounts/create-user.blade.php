@include('engines.postgres._psql')
@include('engines.postgres._accounts')
@include('engines.postgres._primary')
require_databases
created=$(DATABASIFY_VAR_name="$NAME" DATABASIFY_VAR_password="$PASSWORD" pg postgres <<'SQL'
SET log_min_duration_statement = -1; SET log_statement = 'none'; SET log_min_error_statement = panic;
SELECT NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'name') AS created \gset
\if :created
CREATE ROLE :"name" LOGIN;
\endif
ALTER ROLE :"name" LOGIN PASSWORD :'password';
SELECT :'created'
SQL
)
apply_grants
result "$(jq -cn --arg created "$created" '{created: ($created == "t")}')"
