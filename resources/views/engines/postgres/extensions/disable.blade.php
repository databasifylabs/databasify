{!! $sql->connect($schema) !!}
\set SHOW_CONTEXT never
DO $disable$
DECLARE
    detail text;
    config record;
    holds_rows boolean;
BEGIN
    FOR config IN
        SELECT e.extconfig[i]::regclass AS config_table, coalesce(e.extcondition[i], '') AS condition
        FROM pg_extension e
        CROSS JOIN LATERAL generate_subscripts(e.extconfig, 1) AS i
        JOIN pg_class c ON c.oid = e.extconfig[i] AND c.relkind IN ('r', 'p')
        WHERE e.extname = {!! $sql->string($name) !!}
    LOOP
        EXECUTE format('SELECT EXISTS (SELECT FROM %s %s)', config.config_table, config.condition) INTO holds_rows;

        IF holds_rows THEN
            RAISE EXCEPTION '%', format({!! $sql->string("{$name} still keeps your data in %s, so it was not disabled. Delete those rows first.") !!}, config.config_table);
        END IF;
    END LOOP;

    EXECUTE format('DROP EXTENSION IF EXISTS %I', {!! $sql->string($name) !!});
EXCEPTION
    WHEN dependent_objects_still_exist THEN
        GET STACKED DIAGNOSTICS detail = PG_EXCEPTION_DETAIL;
        RAISE EXCEPTION '%', {!! $sql->string("{$name} is still in use, so it was not disabled. Remove what uses it first.") !!} USING DETAIL = detail;
END
$disable$;
SELECT {!! $sql->string("{$name} is disabled in {$schema}.") !!};
