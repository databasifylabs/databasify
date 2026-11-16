@include('engines.mysql-family._mysql')
@include('engines.mysql-family.accounts._revoke')
@foreach ($users as $user)
revoke_ignoring_missing {!! $sh->arg('REVOKE ALL PRIVILEGES ON '.$sql->name($name).'.* FROM '.$sql->string($user)."@'%'") !!}
@endforeach
my '' > /dev/null <<< {!! $sh->arg('DROP DATABASE IF EXISTS '.$sql->name($name)) !!}
echo 'Dropped the database.'
