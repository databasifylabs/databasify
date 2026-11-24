@if ($allowlist !== '')
SELECT format('ALTER ROLE %I IN DATABASE %I %s', r.rolname, d.datname, CASE WHEN has_database_privilege(r.oid, d.oid, 'CREATE') THEN format('SET extwlist.extensions = %L', {!! $sql->string($allowlist) !!}) ELSE 'RESET extwlist.extensions' END) FROM pg_roles r CROSS JOIN pg_database d WHERE r.rolcanlogin AND NOT r.rolsuper AND r.rolname !~ '^pg_' AND NOT d.datistemplate AND d.datname <> 'postgres'@isset($role) AND r.rolname = {!! $sql->string($role) !!}@endisset \gexec
@endif
