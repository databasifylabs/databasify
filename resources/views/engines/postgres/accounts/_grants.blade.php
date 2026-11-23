@php($role = $sql->name($name))
@foreach ($managed as $database)
{!! $sql->connect($database) !!}
SELECT pg_get_userbyid(datdba) AS owner FROM pg_database WHERE datname = current_database() \gset
REVOKE ALL PRIVILEGES ON DATABASE {!! $sql->name($database) !!} FROM {!! $role !!};
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public REVOKE ALL ON TABLES FROM {!! $role !!};
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public REVOKE ALL ON SEQUENCES FROM {!! $role !!};
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM {!! $role !!};
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM {!! $role !!};
REVOKE ALL ON SCHEMA public FROM {!! $role !!};
@endforeach
@foreach ($grants as $grant)
{!! $sql->connect($grant->database) !!}
SELECT pg_get_userbyid(datdba) AS owner FROM pg_database WHERE datname = current_database() \gset
@if ($grant->access === \App\Enums\Databases\GrantAccess::Write)
GRANT ALL PRIVILEGES ON DATABASE {!! $sql->name($grant->database) !!} TO {!! $role !!};
GRANT ALL ON SCHEMA public TO {!! $role !!};
GRANT ALL ON ALL TABLES IN SCHEMA public TO {!! $role !!};
GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO {!! $role !!};
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public GRANT ALL ON TABLES TO {!! $role !!};
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public GRANT ALL ON SEQUENCES TO {!! $role !!};
@include('engines.postgres.extensions._usage', ['role' => $name])
@else
GRANT CONNECT ON DATABASE {!! $sql->name($grant->database) !!} TO {!! $role !!};
GRANT USAGE ON SCHEMA public TO {!! $role !!};
GRANT SELECT ON ALL TABLES IN SCHEMA public TO {!! $role !!};
GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO {!! $role !!};
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public GRANT SELECT ON TABLES TO {!! $role !!};
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public GRANT SELECT ON SEQUENCES TO {!! $role !!};
@endif
@endforeach
@include('engines.postgres.extensions._allowlist', ['role' => $name])
