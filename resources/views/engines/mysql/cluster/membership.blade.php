SET GLOBAL group_replication_ip_allowlist = {!! $sql->string($allowlist) !!};
SET GLOBAL group_replication_group_seeds = {!! $sql->string($seeds) !!};
