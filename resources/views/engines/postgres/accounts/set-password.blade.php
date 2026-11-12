@include('engines.postgres._psql')
@include('engines.postgres._accounts')
@include('engines.postgres._primary')
require_role
DATABASIFY_VAR_name="$NAME" DATABASIFY_VAR_password="$PASSWORD" pg postgres > /dev/null <<'SQL'
SET log_min_duration_statement = -1; SET log_statement = 'none'; SET log_min_error_statement = panic;
ALTER ROLE :"name" PASSWORD :'password'
SQL
result '{}'
