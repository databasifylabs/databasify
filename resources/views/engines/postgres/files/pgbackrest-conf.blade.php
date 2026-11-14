[global]
@foreach ($repository as [$option, $value])
{!! $option !!}={!! $value !!}
@endforeach
@if ($retentionDays !== null)
repo1-retention-full-type=time
repo1-retention-full={!! $retentionDays !!}
@endif
repo1-bundle=y
compress-type=zst
process-max=2
start-fast=y
archive-async=y
archive-push-queue-max=4GiB
spool-path=/srv/postgres/archive/spool
lock-path=/srv/postgres/archive/lock
log-path=/srv/postgres/archive/log
log-level-console=warn
log-level-file=info

[main]
pg1-path=/srv/postgres/data
pg1-port={!! $port !!}
pg1-socket-path=/srv/postgres/run
