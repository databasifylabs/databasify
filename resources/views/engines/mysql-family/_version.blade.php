my_try '' <<'SQL'
@verbatim
SELECT @@version
@endverbatim
SQL
if [ "$MY_ERROR" = unavailable ]; then
    result '{"role":"offline","version":null}'
    exit 0
elif [ -n "$MY_ERROR" ]; then
    databasify_fail "$MY_ERROR" "$MY_ERROR_TEXT"
fi
version=${MY_OUTPUT%%-*}

status_result() {
    result "$(jq -cn --arg role "$1" --arg version "$version" '{role: $role, version: $version}')"
}
