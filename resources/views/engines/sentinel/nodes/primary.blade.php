@include('engines.sentinel._cli')
primary=''
for peer in $PEERS; do
    SENTINEL_HOST=$peer cli_try sentinel_cli SENTINEL get-master-addr-by-name main
    candidate=$(head -n 1 <<< "$CLI_OUTPUT")
    if [ -z "$CLI_ERROR" ] && [[ $candidate =~ ^[0-9a-fA-F.:]+$ ]] && [[ $candidate == *[.:]* ]]; then
        primary=$candidate
        break
    fi
done
if [ -z "$primary" ]; then
    primary=$(sed -n 's/^sentinel monitor main \([^ ]*\) .*/\1/p' "$ROOT/sentinel/sentinel.conf" 2> /dev/null | head -n 1)
fi
result "$(jq -cn --arg primary "$primary" '{primary: $primary}')"
