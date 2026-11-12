listed_databases() {
    pg postgres <<'SQL'
SELECT datname FROM pg_database WHERE NOT datistemplate AND datallowconn ORDER BY datname
SQL
}

granted_databases() {
    jq -r '.[].database' <<< "$GRANTS"
}

require_databases() {
    local listing database
    listing=$(listed_databases)
    while IFS= read -r database; do
        [ -z "$database" ] && continue
        if ! grep -qxF -- "$database" <<< "$listing"; then
            databasify_fail not_found "database \"$database\" does not exist"
        fi
    done < <(granted_databases)
}

role_exists() {
    DATABASIFY_VAR_name="$NAME" pg postgres <<'SQL'
SELECT EXISTS (SELECT FROM pg_roles WHERE rolname = :'name')
SQL
}

require_role() {
    local exists
    exists=$(role_exists)
    if [ "$exists" != t ]; then
        databasify_fail not_found "user \"$NAME\" does not exist"
    fi
}

owner_of() {
    DATABASIFY_VAR_db="$1" pg postgres <<'SQL'
SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = :'db'
SQL
}

revoke_on() {
    local database=$1 owner
    owner=$(owner_of "$database")
    DATABASIFY_VAR_name="$NAME" DATABASIFY_VAR_db="$database" DATABASIFY_VAR_owner="$owner" pg postgres > /dev/null <<'SQL'
REVOKE ALL PRIVILEGES ON DATABASE :"db" FROM :"name"
SQL
    DATABASIFY_VAR_name="$NAME" DATABASIFY_VAR_db="$database" DATABASIFY_VAR_owner="$owner" pg "$database" > /dev/null <<'SQL'
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public REVOKE ALL ON TABLES FROM :"name";
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public REVOKE ALL ON SEQUENCES FROM :"name";
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM :"name";
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM :"name";
REVOKE ALL ON SCHEMA public FROM :"name"
SQL
}

grant_read_on() {
    local database=$1 owner=$2
    DATABASIFY_VAR_name="$NAME" DATABASIFY_VAR_db="$database" DATABASIFY_VAR_owner="$owner" pg postgres > /dev/null <<'SQL'
GRANT CONNECT ON DATABASE :"db" TO :"name"
SQL
    DATABASIFY_VAR_name="$NAME" DATABASIFY_VAR_db="$database" DATABASIFY_VAR_owner="$owner" pg "$database" > /dev/null <<'SQL'
GRANT USAGE ON SCHEMA public TO :"name";
GRANT SELECT ON ALL TABLES IN SCHEMA public TO :"name";
GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO :"name";
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public GRANT SELECT ON TABLES TO :"name";
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public GRANT SELECT ON SEQUENCES TO :"name"
SQL
}

grant_write_on() {
    local database=$1 owner=$2
    DATABASIFY_VAR_name="$NAME" DATABASIFY_VAR_db="$database" DATABASIFY_VAR_owner="$owner" pg postgres > /dev/null <<'SQL'
GRANT ALL PRIVILEGES ON DATABASE :"db" TO :"name"
SQL
    DATABASIFY_VAR_name="$NAME" DATABASIFY_VAR_db="$database" DATABASIFY_VAR_owner="$owner" pg "$database" > /dev/null <<'SQL'
GRANT ALL ON SCHEMA public TO :"name";
GRANT ALL ON ALL TABLES IN SCHEMA public TO :"name";
GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO :"name";
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public GRANT ALL ON TABLES TO :"name";
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public GRANT ALL ON SEQUENCES TO :"name"
SQL
}

apply_grants() {
    local database access owner listing
    listing=$(listed_databases)
    while IFS= read -r database; do
        [ -z "$database" ] && continue
        if jq -e --arg db "$database" 'index($db)' <<< "$RESERVED_DATABASES" > /dev/null; then
            continue
        fi
        revoke_on "$database"
    done <<< "$listing"
    while IFS=$'\t' read -r database access; do
        [ -z "$database" ] && continue
        owner=$(owner_of "$database")
        if [ "$access" = write ]; then
            grant_write_on "$database" "$owner"
        else
            grant_read_on "$database" "$owner"
        fi
    done < <(jq -r '.[] | [.database, .access] | @tsv' <<< "$GRANTS")
}
