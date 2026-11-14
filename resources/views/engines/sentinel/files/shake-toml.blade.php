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
cluster = false
address = {!! $sourceAddress !!}
username = {!! $sourceUser !!}
password = {!! $sourcePassword !!}
tls = {!! $sourceTls !!}

[redis_writer]
cluster = false
address = {!! $targetAddress !!}
password = {!! $targetPassword !!}
tls = false

[advanced]
dir = {!! $dir !!}
status_port = 0
log_file = "shake.log"
log_level = "info"
log_interval = 5
rdb_restore_command_behavior = "rewrite"
