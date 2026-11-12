source_cnf() {
    local cnf slash='\' double='"' user=$SOURCE_USER password=$SOURCE_PASSWORD
    user=${user//"$slash"/"$slash$slash"}
    user=${user//"$double"/"$slash$double"}
    password=${password//"$slash"/"$slash$slash"}
    password=${password//"$double"/"$slash$double"}
    cnf=$(mktemp)
    chmod 600 "$cnf"
    {
        printf '[client]\nhost=%s\nport=%s\nuser="%s"\npassword="%s"\nconnect-timeout=15\n' "$SOURCE_HOST" "$SOURCE_PORT" "$user" "$password"
@if ($engine->id === 'mysql')
        if [ "$SOURCE_TLS" = true ]; then
            printf 'ssl-mode=REQUIRED\n'
        else
            printf 'ssl-mode=PREFERRED\n'
        fi
@else
        if [ "$SOURCE_TLS" = true ]; then
            printf 'ssl\nskip-ssl-verify-server-cert\n'
        else
            printf 'skip-ssl-verify-server-cert\n'
        fi
@endif
    } > "$cnf"
    printf '%s' "$cnf"
}
