@if ($continuous)
[sync_reader]
sync_rdb = true
sync_aof = true
@else
[scan_reader]
scan = true
ksn = false
count = 100
@endif
address = {!! $sourceAddress !!}
username = {!! $sourceUsername !!}
password = {!! $sourcePassword !!}
tls = {{ $sourceTls ? 'true' : 'false' }}

[redis_writer]
address = {!! $writerAddress !!}
password = {!! $writerPassword !!}
tls = false

[advanced]
dir = {!! $workDirectory !!}
status_port = 0
log_file = "shake.log"
log_level = "info"
log_interval = 5
rdb_restore_command_behavior = "rewrite"
