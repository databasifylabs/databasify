@include('engines.mysql-family._mysql')
@include('engines.mysql-family.accounts._revoke')
@php($account = $sql->string($name)."@'%'")
@include('engines.mysql-family.accounts._grants')
@if ($replication)
my '' > /dev/null <<< {!! $sh->arg("GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO {$account}") !!}
@endif
echo 'Granted the user its databases.'
