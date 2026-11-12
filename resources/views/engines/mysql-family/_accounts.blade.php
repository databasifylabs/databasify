quoted_name=$(my_string "$NAME")

go_quoted() {
    jq -rn --arg value "$1" '$value | tojson'
}

each_of() {
    local item
    while IFS= read -r item; do
        [ -n "$item" ] && printf '%s' "$item" | base64 -d && printf '\0'
    done < <(jq -r '.[] | @base64' <<< "$1")
}

all_databases() {
    my '' <<'SQL'
SELECT COALESCE(JSON_ARRAYAGG(SCHEMA_NAME), JSON_ARRAY()) FROM information_schema.SCHEMATA
SQL
}

managed_databases() {
    all_databases | jq -c --argjson reserved "$RESERVED_DATABASES" 'map(select(. as $name | $reserved | index($name) | not))'
}

user_exists() {
    local count
    count=$(my '' <<SQL
SELECT COUNT(*) FROM mysql.user WHERE User = $quoted_name AND Host = '%'
SQL
) || exit 1
    [ "$count" != 0 ]
}

require_user() {
    if ! user_exists; then
        databasify_fail not_found "user $(go_quoted "$NAME") does not exist"
    fi
}

require_databases() {
    local existing database
    existing=$(all_databases)
    while IFS= read -r -d '' database; do
        if ! jq -e --arg name "$database" 'index($name) != null' <<< "$existing" > /dev/null; then
            databasify_fail not_found "database $(go_quoted "$database") does not exist"
        fi
    done < <(each_of "$(jq -c 'map(.database)' <<< "$GRANTS")")
}

revoke_on() {
    my_try '' <<SQL
REVOKE ALL PRIVILEGES ON $(my_name "$1").* FROM $(my_string "$2")@'%'
SQL
    if [ -n "$MY_ERROR" ] && [[ $MY_ERROR_TEXT != 'Error 1141 '* ]]; then
        databasify_fail "$MY_ERROR" "$MY_ERROR_TEXT"
    fi
}

apply_grants() {
    local database access privileges managed
    managed=$(managed_databases)
    while IFS= read -r -d '' database; do
        revoke_on "$database" "$NAME"
    done < <(each_of "$managed")
    while IFS= read -r -d '' database; do
        access=$(jq -r --arg name "$database" 'map(select(.database == $name))[0].access' <<< "$GRANTS")
        privileges='SELECT, SHOW VIEW'
        if [ "$access" = write ]; then
            privileges='ALL PRIVILEGES'
        fi
        my '' > /dev/null <<SQL
GRANT $privileges ON $(my_name "$database").* TO $quoted_name@'%'
SQL
    done < <(each_of "$(jq -c 'map(.database)' <<< "$GRANTS")")
}
