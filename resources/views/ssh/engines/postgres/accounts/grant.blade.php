\connect postgres
GRANT ALL PRIVILEGES ON DATABASE "{!! $database !!}" TO "{!! $user !!}";
\connect "{!! $database !!}"
GRANT ALL ON SCHEMA public TO "{!! $user !!}";
