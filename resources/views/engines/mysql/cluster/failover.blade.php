SELECT group_replication_set_as_primary(COALESCE((SELECT MEMBER_ID FROM performance_schema.replication_group_members WHERE MEMBER_HOST = {!! $sql->string($target) !!}), ''));
