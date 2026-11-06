@include('ssh.engines.redis.helpers')
apply() { result=$(vcli CONFIG SET "$1" "$2"); [ "$result" = 'OK' ] || { echo "could not set $1: $result"; exit 1; }; }
@if (! $persistent)
apply save ''
apply appendonly no
@else
apply appendonly yes
apply save '{{ $snapshot }}'
@endif
@foreach ($settings as $key => $value)
@if ($key === 'pubsub-output-buffer-limit')
apply client-output-buffer-limit 'pubsub {{ (int) $value }}mb {{ intdiv((int) $value, 4) }}mb 60'
@else
apply {{ $key }} '{{ $value }}'
@endif
@endforeach
@foreach ($resets as $key)
apply {{ $key }} ''
@endforeach
echo "settings applied"
