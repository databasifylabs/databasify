@include('engines.sentinel._cli')
answers=()
@foreach ($peers as $peer)
SENTINEL_HOST={!! $sh->arg($peer) !!} cli_try sentinel_cli SENTINEL get-master-addr-by-name main
answers+=("$CLI_OUTPUT")
@endforeach
configured=$(sed -n 's/^sentinel monitor main \([^ ]*\) .*/\1/p' "$ROOT/sentinel/sentinel.conf" 2> /dev/null | head -n 1 || true)
output=$(jq -cn --arg configured "$configured" '{answers: $ARGS.positional, configured: $configured}' --args "${answers[@]}")
result "$output"
