if ! initiated; then
    mongo_open "rs.initiate({ _id: '{{ $replicaSet }}', members: [{ _id: 0, host: '{{ $privateIp }}:{{ $clientPort }}' }] }).ok" > /dev/null
    echo "initiated replica set {{ $replicaSet }} on {{ $privateIp }}"
fi
wait_for 300 writable_primary
if ! can_log_in; then
    mongo_open "db.getSiblingDB('admin').createUser({ user: 'root', pwd: require('fs').readFileSync('{{ $rootPasswordFile }}', 'utf8').trim(), roles: ['root'], mechanisms: ['SCRAM-SHA-256'] }, { w: 'majority' }).ok" > /dev/null
    can_log_in
    echo "created the root user"
fi
