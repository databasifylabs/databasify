@foreach ($databases as $database)
\connect "{!! $database !!}"
REASSIGN OWNED BY "{!! $name !!}" TO postgres;
DROP OWNED BY "{!! $name !!}";
@endforeach
\connect postgres
DROP ROLE IF EXISTS "{!! $name !!}";
