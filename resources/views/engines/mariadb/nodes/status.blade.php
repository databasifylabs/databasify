@verbatim
SELECT JSON_OBJECT('role', CASE
    WHEN @@wsrep_on = 1 AND NOT (COALESCE((SELECT VARIABLE_VALUE FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME = 'WSREP_CLUSTER_STATUS'), '') = 'Primary' AND COALESCE((SELECT VARIABLE_VALUE FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME = 'WSREP_LOCAL_STATE_COMMENT'), '') = 'Synced') THEN 'unknown'
    WHEN UPPER(CAST(@@read_only AS CHAR)) IN ('0', 'OFF') THEN 'primary'
    WHEN @@wsrep_on = 1 OR COALESCE((SELECT VARIABLE_VALUE FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME = 'SLAVES_RUNNING'), '0') <> '0' THEN 'replica'
    ELSE 'unknown'
END, 'version', SUBSTRING_INDEX(@@version, '-', 1));
@endverbatim
