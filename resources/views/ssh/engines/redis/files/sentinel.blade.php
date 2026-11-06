port {!! $sentinelPort !!}
bind {!! $ip !!} 127.0.0.1
supervised no
dir /srv/{!! $flavor !!}/sentinel
requirepass {!! $password !!}
sentinel announce-ip {!! $ip !!}
sentinel announce-port {!! $sentinelPort !!}
sentinel sentinel-pass {!! $password !!}
sentinel monitor {!! $master !!} {!! $primary !!} {!! $dataPort !!} {!! $quorum !!}
sentinel auth-pass {!! $master !!} {!! $password !!}
sentinel down-after-milliseconds {!! $master !!} 5000
sentinel failover-timeout {!! $master !!} 60000
sentinel parallel-syncs {!! $master !!} 1
