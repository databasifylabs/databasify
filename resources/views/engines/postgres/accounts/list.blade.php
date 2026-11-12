@include('engines.postgres._psql')
users=$(pg postgres <<'SQL'
SELECT rolname FROM pg_roles WHERE rolname !~ '^pg_'
SQL
)
databases=$(pg postgres <<'SQL'
SELECT datname FROM pg_database WHERE NOT datistemplate
SQL
)
result "$(jq -cn --arg users "$users" --arg databases "$databases" '{users: ($users | split("\n") | map(select(. != ""))), databases: ($databases | split("\n") | map(select(. != "")))}')"
