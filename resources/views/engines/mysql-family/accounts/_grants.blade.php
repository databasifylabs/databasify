@foreach ($managed as $database)
revoke_ignoring_missing {!! $sh->arg('REVOKE ALL PRIVILEGES ON '.$sql->name($database).'.* FROM '.$account) !!}
@endforeach
@foreach ($grants as $grant)
my '' > /dev/null <<< {!! $sh->arg('GRANT '.$grant->privileges().' ON '.$sql->name($grant->database).'.* TO '.$account) !!}
@endforeach
