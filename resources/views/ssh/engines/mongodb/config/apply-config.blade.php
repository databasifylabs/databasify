@include('ssh.engines.mongodb.helpers')
apply() { mongo_js "$1" > /dev/null || { echo "could not set $2"; exit 1; }; }
@foreach ($settings as $key => $value)
@switch($key)
@case('slowOpThresholdMs')
apply "db.adminCommand({ profile: -1, slowms: Number('{{ $value }}') }).ok" {{ $key }}
@break
@case('verbosity')
apply "db.adminCommand({ setParameter: 1, logLevel: Number('{{ $value }}') }).ok" {{ $key }}
@break
@case('defaultReadConcern')
@case('defaultWriteConcern')
@break
@default
apply "db.adminCommand({ setParameter: 1, {{ $key }}: Number('{{ $value }}') }).ok" {{ $key }}
@endswitch
@endforeach
@if (array_intersect(array_merge(array_keys($settings), $resets), $clusterWideKeys) !== [])
if writable_primary; then
    apply_rw_concern || { echo "could not set the default read and write concern"; exit 1; }
    echo "default read and write concern set for the replica set"
fi
@endif
@foreach (array_diff($resets, $clusterWideKeys) as $key)
echo "{{ $key }} keeps its current value until it is set again"
@endforeach
record_running_config
echo "settings applied"
