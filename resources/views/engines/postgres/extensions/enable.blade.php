{!! $sql->connect($schema) !!}
CREATE EXTENSION IF NOT EXISTS {!! $sql->name($name) !!} CASCADE;
SELECT format('%s %s is enabled in %s.', extname, extversion, current_database()) FROM pg_extension WHERE extname = {!! $sql->string($name) !!};
@include('engines.postgres.extensions._usage', ['role' => null])
