SELECT JSON_OBJECT(
    'connections', COALESCE((SELECT CAST(VARIABLE_VALUE AS SIGNED) FROM performance_schema.global_status WHERE VARIABLE_NAME = 'Threads_connected'), 0),
    'queries', COALESCE((SELECT CAST(VARIABLE_VALUE AS SIGNED) FROM performance_schema.global_status WHERE VARIABLE_NAME = 'Questions'), 0),
    'dataMb', (SELECT FLOOR(COALESCE(SUM(data_length + index_length), 0) / 1048576) FROM information_schema.tables)
);
