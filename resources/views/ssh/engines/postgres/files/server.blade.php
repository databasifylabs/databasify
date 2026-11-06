listen_addresses = '*'
port = '{!! $port !!}'
hba_file = '/srv/postgres/conf/pg_hba.conf'
@include('ssh.engines.shared.options', ['options' => $clusterWide, 'separator' => ' = '])
@include('ssh.engines.shared.options', ['options' => $local, 'separator' => ' = '])
