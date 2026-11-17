@include('engines.mysql-family._mysql')
@include('engines.mysql-family.accounts._revoke')
@php($account = $sql->string($name)."@'%'")
my '' > /dev/null <<< {!! $sh->arg("CREATE USER IF NOT EXISTS {$account} {$identified} REQUIRE NONE; ALTER USER {$account} {$identified} REQUIRE NONE;") !!}
@include('engines.mysql-family.accounts._grants')
echo 'Saved the user and its grants.'
