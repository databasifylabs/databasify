@include('engines.postgres._psql')
@include('engines.postgres._primary')
created=$(DATABASIFY_VAR_name="$NAME" pg postgres <<'SQL'
SELECT NOT EXISTS (SELECT FROM pg_database WHERE datname = :'name') AS created \gset
\if :created
CREATE DATABASE :"name";
\endif
SELECT :'created'
SQL
)
result "$(jq -cn --arg created "$created" '{created: ($created == "t")}')"
