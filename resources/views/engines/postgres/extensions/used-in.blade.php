@include('engines.postgres._psql')
NAME={!! $sh->arg($name) !!}
used=''
while IFS= read -r database; do
    [ -z "$database" ] && continue
    if [ "$(pg "$database" <<< "SELECT count(*) FROM pg_extension WHERE extname = $(pg_string "$NAME")")" = 1 ]; then
        used+="$database"$'\n'
    fi
done <<< "$(pg postgres <<< "SELECT datname FROM pg_database WHERE NOT datistemplate AND datname <> 'postgres' ORDER BY datname")"
result "$(jq -cn --arg used "$used" '{schemas: ($used | split("\n") | map(select(. != "")))}')"
