@include('engines.mysql-family._mysql')
users=$(my '' <<'SQL'
SELECT COALESCE(JSON_ARRAYAGG(User), JSON_ARRAY()) FROM mysql.user WHERE Host = '%'
SQL
)
databases=$(my '' <<'SQL'
SELECT COALESCE(JSON_ARRAYAGG(SCHEMA_NAME), JSON_ARRAY()) FROM information_schema.SCHEMATA
SQL
)
result "$(jq -cn --argjson users "$users" --argjson databases "$databases" '{users: $users, databases: $databases}')"
