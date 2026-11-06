port {!! $dataPort !!}
bind * -::*
protected-mode yes
tcp-backlog 511
tcp-keepalive 300
supervised no
daemonize no
loglevel notice
logfile ""
dir /srv/{!! $flavor !!}/data
tls-port {!! $tlsPort !!}
tls-cert-file /srv/{!! $flavor !!}/tls/server.pem
tls-key-file /srv/{!! $flavor !!}/tls/server.pem
tls-ca-cert-file /srv/{!! $flavor !!}/tls/ca.crt
tls-auth-clients no
tls-protocols "TLSv1.2 TLSv1.3"
requirepass {!! $password !!}
masterauth {!! $password !!}
maxmemory-clients 10%
client-output-buffer-limit normal 256mb 64mb 60
lazyfree-lazy-expire yes
lazyfree-lazy-server-del yes
lazyfree-lazy-user-del yes
lazyfree-lazy-user-flush yes
dynamic-hz yes
hz 10
activedefrag yes
jemalloc-bg-thread yes
latency-monitor-threshold 100
loadmodule /usr/local/lib/redis/modules/rejson.so
loadmodule /usr/local/lib/redis/modules/redisearch.so
loadmodule /usr/local/lib/redis/modules/redisbloom.so
loadmodule /usr/local/lib/redis/modules/redistimeseries.so
@if ($persistent)
save {!! $snapshot !!}
stop-writes-on-bgsave-error yes
dbfilename dump.rdb
appendonly yes
appendfilename "appendonly.aof"
appenddirname "appendonlydir"
appendfsync everysec
aof-use-rdb-preamble yes
shutdown-on-sigterm default
@else
save ""
appendonly no
@endif
@include('ssh.engines.shared.options', ['separator' => ' '])
@if ($clustered)
replica-announce-ip {!! $ip !!}
min-replicas-to-write {!! $minReplicas !!}
min-replicas-max-lag 10
@if ($readOnly)
replica-priority 0
@endif
@if ($primary)
replicaof {!! $primary !!} {!! $dataPort !!}
@endif
@endif
