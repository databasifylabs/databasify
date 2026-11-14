@include('engines.mongodb._mongo')
@include('engines.mongodb._pbm')
@include('engines.mongodb._pcsm')
finalized_marker="$ROOT/state/mongodb-migration.finalized"
mark_finalized() {
    { mkdir -p "${finalized_marker%/*}" && : > "$finalized_marker"; } || fail_task "could not record the confirmed finalize in $finalized_marker"
}
if status=$(pcsm_status); then
    step=$(json_eval "$status" 'const state = String(input.state || ""); const synced = Boolean(input.initialSync && input.initialSync.completed); const cutover = process.env.MIGRATION_CUTOVER === "true"; print(state === "finalizing" ? "wait" : (state === "finalized" ? "done" : (["running", "paused"].includes(state) && (cutover || synced) ? "finalize" : (state === "pausing" && (cutover || synced) ? "busy" : (cutover ? "refuse " + state + (input.error ? ": " + String(input.error) : "") : (state === "running" ? "pause" : "none"))))))') \
        || fail_task "could not read the ClusterSync status: $status"
    if [ "$step" = done ]; then
        mark_finalized
    elif [[ $step == refuse\ * ]]; then
        fail_task "ClusterSync cannot finish the cutover (${step#refuse }); the target keeps its ClusterSync state, fix ClusterSync and clean up again"
    elif [ "$step" = busy ]; then
        fail_task 'ClusterSync is pausing before it can finalize, clean up again once it paused'
    elif [ "$step" = pause ]; then
        pcsm_post /pause
    elif [ "$step" = finalize ] || [ "$step" = wait ]; then
        if [ "$step" = finalize ]; then
            pcsm_post /finalize
        fi
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
        wait_until 21600 2 finalized || fail_task 'ClusterSync did not finalize within 6h0m0s'
    fi
elif [ "${MIGRATION_CUTOVER:-false}" = true ]; then
    if unit_active mongodb-migration; then
        fail_task 'ClusterSync is running but does not answer, clean up again once it answers'
    fi
    [ -f "$finalized_marker" ] || fail_task 'the cutover never confirmed finalized; check indexes before cleaning up'
fi
databasify ctl stop mongodb-migration > /dev/null 2>&1 || true
databasify ctl reset-failed mongodb-migration > /dev/null 2>&1 || true
mongo_js root > /dev/null <<'JS'
main(() => { run('percona_clustersync_mongodb', { dropDatabase: 1 }); result({}); });
JS
rm -f "$finalized_marker"
echo 'Stopped the migration.'
