option_value() {
    local value=$1 slash='\' double='"'
    value=${value//"$slash"/"$slash$slash"}
    value=${value//"$double"/"$slash$double"}
    printf '"%s"' "$value"
}

remote_cnf() {
    local cnf
    cnf=$(mktemp)
    chmod 600 "$cnf"
    printf '[client]\nhost=%s\nport=25060\nuser=%s\npassword=%s\nssl\nskip-ssl-verify-server-cert\n' "$1" "$(option_value databasify_repl)" "$(option_value "$2")" > "$cnf"
    printf '%s' "$cnf"
}
