{
@isset($options['verbosity'])
    "systemLog": {
        "verbosity": {!! $options['verbosity'] !!}
    },
@endisset
@isset($options['slowOpThresholdMs'])
    "operationProfiling": {
        "slowOpThresholdMs": {!! $options['slowOpThresholdMs'] !!}
    },
@endisset
@isset($options['transactionLifetimeLimitSeconds'])
    "setParameter": {
        "transactionLifetimeLimitSeconds": {!! $options['transactionLifetimeLimitSeconds'] !!}
    },
@endisset
    "net": {
        "port": {!! $port !!},
        "bindIpAll": true,
        "maxIncomingConnections": {!! $options['maxIncomingConnections'] !!},
        "tls": {
            "mode": "requireTLS",
            "certificateKeyFile": "/srv/mongodb/tls/server.pem",
            "CAFile": "/srv/mongodb/tls/ca.crt",
            "allowConnectionsWithoutCertificates": true,
            "disabledProtocols": "TLS1_0,TLS1_1"
        }
    },
    "storage": {
        "dbPath": "/srv/mongodb/data",
        "wiredTiger": {
            "engineConfig": {
                "cacheSizeGB": {!! $options['cacheSizeGB'] !!}
            }
        }
    },
    "security": {
        "keyFile": "/srv/mongodb/conf/keyfile",
        "authorization": "enabled"
    },
    "replication": {
        "replSetName": "databasify"
    }
}
