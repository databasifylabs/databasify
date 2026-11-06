@include('ssh.engines.mongodb.helpers')
@include('ssh.databases.helpers')
databasify ctl daemon-reload
restart_unless_current
wait_for 300 answering
@if ($seed && ($bootstrapsCluster || ! $clustered))
@include('ssh.engines.mongodb.lifecycle.initiate')
@endif
if writable_primary; then
    apply_rw_concern || { echo "could not set the default read and write concern"; exit 1; }
fi
@if (in_array($pbmAgentUnit, $processes, true))
mongo_run_on_primary <<'JS' > /dev/null || echo "no primary to create the PBM user on yet"
@include('ssh.engines.mongodb.archive.users')
JS
databasify ctl enable {{ $pbmAgentUnit }} > /dev/null 2>&1
databasify ctl restart {{ $pbmAgentUnit }}
echo "{{ $pbmAgentUnit }}: $(databasify ctl is-active {{ $pbmAgentUnit }} || true)"
@endif
echo "mongod: $(databasify ctl is-active mongod || true)"
