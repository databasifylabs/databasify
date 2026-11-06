@include('ssh.databases.helpers')
@if ($clustered)
etcd_hosts='{{ collect(explode(',', $hosts))->map(fn (string $host): string => $host.':'.$etcdClientPort)->implode(',') }}'
sed -i "s|^  hosts: .*|  hosts: '$etcd_hosts'|" "$dir/conf/patroni.yml"
databasify ctl reload databasify-patroni
echo "patroni finds etcd on $etcd_hosts"
@endif
