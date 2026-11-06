@include('ssh.engines.mysql.helpers')
@include('ssh.databases.helpers')
databasify ctl enable mysql > /dev/null
just_booted() { [ "$(msql "SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME = 'Uptime'" || echo 0)" -lt 60 ]; }
settled() { in_the_group || ! just_booted; }
if runs_current_config; then
    wait_for 300 settled || true
fi
if in_cluster; then
    refresh_group_seeds
fi
if runs_current_config && in_the_group; then
    echo "mysql already runs this configuration, no restart needed"
else
    databasify ctl restart mysql
    up() { msql "SELECT 1" > /dev/null; }
    wait_for 900 up
    record_running_config
fi
if in_cluster && copied_identity; then
@include('ssh.engines.mysql.lines.9_7.cluster.reprovision')
@if ($clustered && ! $bootstrapsCluster)
elif ! in_cluster; then
@include('ssh.engines.mysql.lines.9_7.cluster.rejoin-empty')
@endif
fi
@if ($bootstrapsCluster)
@include('ssh.engines.mysql.lines.9_7.cluster.create-cluster')
@endif
echo "mysql: $(databasify ctl is-active mysql || true)"
