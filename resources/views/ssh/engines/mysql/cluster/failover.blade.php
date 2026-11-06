@include('ssh.engines.mysql.helpers')
@include('ssh.databases.helpers')
target_runs_other_version() { [ "$(msql "SELECT COUNT(*) FROM performance_schema.replication_group_members target JOIN performance_schema.replication_group_members own ON own.MEMBER_ID = @@@server_uuid WHERE target.MEMBER_HOST = '{{ Illuminate\Support\Str::before($target, ':') }}' AND target.MEMBER_VERSION <> own.MEMBER_VERSION" || echo 0)" != "0" ]; }
if target_runs_other_version; then
    msql "STOP GROUP_REPLICATION" > /dev/null
    left_the_group() { [ -z "$(own_member)" ]; }
    wait_for 120 left_the_group
    echo "{{ $target }} runs another MySQL release, which group replication never appoints while this node is in the group, so this node left and the others elected a new primary"
else
    cluster_admin "dba.get_cluster().set_primary_instance('{{ $target }}')" > /dev/null
    demoted() { own_member | grep -q '^SECONDARY'; }
    wait_for 120 demoted
    echo "primary moved to {{ $target }}"
fi
