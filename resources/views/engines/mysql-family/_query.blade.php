@include('engines.mysql-family._mysql')
@if ($json)
output=$(printf '%s' {!! $sh->arg($query) !!} | base64 -d | my '')
result "$output"
@else
printf '%s' {!! $sh->arg($query) !!} | base64 -d | my ''
@endif
