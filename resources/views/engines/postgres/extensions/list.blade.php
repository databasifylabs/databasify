{!! $sql->connect($schema) !!}
SELECT json_build_object('extensions', (SELECT COALESCE(json_agg(json_build_object('name', e.extname, 'version', e.extversion, 'defaultVersion', COALESCE(a.default_version, e.extversion)) ORDER BY e.extname), '[]') FROM pg_extension e LEFT JOIN pg_available_extensions a ON a.name = e.extname WHERE e.extname <> 'plpgsql'));
