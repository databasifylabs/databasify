port {!! $sentinelPort !!}
bind {!! $ip !!} 127.0.0.1
daemonize no
dir {!! $home !!}/sentinel
requirepass {!! $password !!}
sentinel announce-ip {!! $ip !!}
sentinel announce-port {!! $sentinelPort !!}
sentinel sentinel-pass {!! $password !!}
sentinel monitor main {!! $primary !!} {!! $dataPort !!} {!! $quorum !!}
sentinel auth-pass main {!! $password !!}
sentinel down-after-milliseconds main 5000
sentinel failover-timeout main 60000
sentinel parallel-syncs main 1
