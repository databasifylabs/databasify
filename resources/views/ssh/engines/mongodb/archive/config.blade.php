{
    "storage": {!! $storage !!},
    "pitr": {
        "enabled": {!! $slicesOplog ? 'true' : 'false' !!},
        "oplogSpanMin": 10
    },
    "backup": {
        "compression": "zstd"
    }
}
