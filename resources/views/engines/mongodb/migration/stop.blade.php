@include('engines.mongodb._mongo')
@include('engines.mongodb._pbm')
@include('engines.mongodb._pcsm')
finalized_marker="$ROOT/state/mongodb-migration.finalized"
mark_finalized() {
    { mkdir -p "${finalized_marker%/*}" && : > "$finalized_marker"; } || fail_task "could not record the confirmed finalize in $finalized_marker"
}
clustersync_step() {
    json_eval "$1" "$(cat <<'JS'
function stepOf(status, cutover) {
  const state = String(status.state || "");
  if (state === "finalizing") return "wait";
  if (state === "finalized") return "done";
  const finishing = cutover || Boolean(status.initialSync && status.initialSync.completed);
  if (["running", "paused"].includes(state) && finishing) return "finalize";
  if (state === "pausing" && finishing) return "busy";
  if (cutover) return "refuse " + state + (status.error ? ": " + String(status.error) : "");
  return state === "running" ? "pause" : "none";
}
print(stepOf(input, @json($cutover)));
JS
)"
}
finalized() {
    local state
    state=$(pcsm_status) || fail_task 'ClusterSync stopped answering while it finalized'
    state=$(json_eval "$state" 'print(input.state === "failed" ? "failed " + String(input.error || "") : String(input.state || ""))')
    if [[ $state == failed\ * ]]; then
        fail_task "ClusterSync could not finalize: ${state#failed }"
    fi
    [ "$state" = finalized ] || return 1
    mark_finalized
}
wait_for_finalize() {
    wait_until 21600 2 finalized || fail_task 'ClusterSync did not finalize within 6h0m0s'
}
if status=$(pcsm_status); then
    step=$(clustersync_step "$status") || fail_task "could not read the ClusterSync status: $status"
    case $step in
        done)
            mark_finalized
            ;;
        refuse\ *)
            fail_task "ClusterSync cannot finish the cutover (${step#refuse }); the target keeps its ClusterSync state, fix ClusterSync and clean up again"
            ;;
        busy)
            fail_task 'ClusterSync is pausing before it can finalize, clean up again once it paused'
            ;;
        pause)
            pcsm_post /pause
            ;;
        finalize)
            pcsm_post /finalize
            wait_for_finalize
            ;;
        wait)
            wait_for_finalize
            ;;
        none) ;;
        *)
            fail_task "ClusterSync gave an unknown stop step '$step', so nothing was cleaned up"
            ;;
    esac
@if ($cutover)
elif unit_active mongodb-migration; then
    fail_task 'ClusterSync is running but does not answer, clean up again once it answers'
elif [ ! -f "$finalized_marker" ]; then
    fail_task 'the cutover never confirmed finalized; check indexes before cleaning up'
@endif
fi
databasify ctl stop mongodb-migration > /dev/null 2>&1 || true
databasify ctl reset-failed mongodb-migration > /dev/null 2>&1 || true
mongo_js root > /dev/null <<'JS'
main(() => { run('percona_clustersync_mongodb', { dropDatabase: 1 }); result({}); });
JS
rm -f "$finalized_marker"
echo 'Stopped the migration.'
