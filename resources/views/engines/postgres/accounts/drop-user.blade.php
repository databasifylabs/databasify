SELECT EXISTS (SELECT FROM pg_roles WHERE rolname = {!! $sql->string($name) !!}) AS present \gset
\if :present
@foreach (['postgres', ...$managed] as $database)
{!! $sql->connect($database) !!}
REASSIGN OWNED BY {!! $sql->name($name) !!} TO postgres;
DROP OWNED BY {!! $sql->name($name) !!};
@endforeach
DROP ROLE {!! $sql->name($name) !!};
\endif
