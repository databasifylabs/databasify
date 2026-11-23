{!! $sql->connect($schema) !!}
@if ($name === 'postgis')
SELECT postgis_extensions_upgrade();
@else
ALTER EXTENSION {!! $sql->name($name) !!} UPDATE;
@endif
SELECT format('%s is updated to %s in %s.', extname, extversion, current_database()) FROM pg_extension WHERE extname = {!! $sql->string($name) !!};
