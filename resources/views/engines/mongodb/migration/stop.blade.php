@include('engines.mongodb._mongo')
@include('engines.mongodb._pbm')
@include('engines.mongodb._pcsm')
if status=$(pcsm_status); then
    step=$(json_eval "$status" 'const synced = input.state === "running" && input.initialSync && input.initialSync.completed; print(synced ? "finalize" : (input.state === "running" ? "pause" : "none"))') || step=none
    if [ "$step" = pause ]; then
        pcsm_post /pause
    elif [ "$step" = finalize ]; then
        pcsm_post /finalize
        finalized() {
            local state
            state=$(pcsm_status) || databasify_fail internal 'ClusterSync stopped answering while it finalized'
            state=$(json_eval "$state" 'print(input.state === "failed" ? "failed " + String(input.error || "") : String(input.state || ""))')
            if [[ $state == failed\ * ]]; then
                databasify_fail internal "ClusterSync could not finalize: ${state#failed }"
            fi
            [ "$state" = finalized ]
        }
        wait_until 3600 2 finalized || databasify_fail internal 'the server was not ready within 1h0m0s'
    fi
fi
databasify ctl stop mongodb-migration > /dev/null 2>&1 || true
databasify ctl reset-failed mongodb-migration > /dev/null 2>&1 || true
mongo_js root > /dev/null <<'JS'
main(() => { run('percona_clustersync_mongodb', { dropDatabase: 1 }); result({}); });
JS
result '{}'
