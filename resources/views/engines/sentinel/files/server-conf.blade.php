port {!! $dataPort !!}
bind * -::*
protected-mode yes
tcp-backlog 511
tcp-keepalive 300
daemonize no
loglevel notice
logfile ""
dir {!! $home !!}/data
tls-port {!! $tlsPort !!}
tls-cert-file {!! $home !!}/tls/server.pem
tls-key-file {!! $home !!}/tls/server.pem
tls-ca-cert-file {!! $home !!}/tls/ca.crt
tls-auth-clients no
tls-protocols "TLSv1.2 TLSv1.3"
requirepass {!! $password !!}
masterauth {!! $password !!}
maxmemory-clients 10%
lazyfree-lazy-expire yes
lazyfree-lazy-server-del yes
lazyfree-lazy-user-del yes
lazyfree-lazy-user-flush yes
activedefrag yes
latency-monitor-threshold 100
@if ($persistent)
save {!! $snapshot !!}
stop-writes-on-bgsave-error yes
dbfilename dump.rdb
appendonly yes
appendfilename "appendonly.aof"
appenddirname "appendonlydir"
appendfsync everysec
aof-use-rdb-preamble yes
@else
save ""
appendonly no
@endif
@foreach ($directives as $directive)
{!! $directive !!}
@endforeach
@if ($clustered)
replica-announce-ip {!! $ip !!}
min-replicas-to-write {!! $requiredReplicas !!}
min-replicas-max-lag 10
@if ($readOnly)
replica-priority 0
@endif
@if ($replicaOf !== null)
replicaof {!! $replicaOf !!} {!! $dataPort !!}
@endif
@endif
