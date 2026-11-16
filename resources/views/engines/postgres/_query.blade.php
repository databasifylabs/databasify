@include('engines.postgres._psql')
@unless ($json)
@include('engines.postgres._primary')
@endunless
PG_SESSION='-c log_statement=none -c log_min_duration_statement=-1 -c log_min_error_statement=panic -c standard_conforming_strings=on'
@if ($json)
output=$(printf '%s' {!! $sh->arg($query) !!} | base64 -d | pg postgres)
result "$output"
@else
printf '%s' {!! $sh->arg($query) !!} | base64 -d | pg postgres
@endif
