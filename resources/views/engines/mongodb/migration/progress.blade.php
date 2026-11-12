@include('engines.mongodb._mongo')
@include('engines.mongodb._pbm')
@include('engines.mongodb._pcsm')
property() {
    databasify ctl show -p "$1" --value mongodb-migration 2> /dev/null || true
}

if [ "$(property LoadState)" = not-found ]; then
    result '{"copy":"lost","log":"","lagSeconds":null}'
    exit 0
fi
copy=failed
if [ "$(property ActiveState)" = active ] && [ "$(property SubState)" = running ]; then
    copy=running
elif [ "$(property ExecMainStatus)" = 0 ]; then
    copy=succeeded
fi
log=$(tail -n 3 "$ROOT/logs/mongodb-migration.log" 2> /dev/null | tr '\n' ' ' | sed 's/ $//' || true)
lag=null
if status=$(pcsm_status); then
    lag=$(json_eval "$status" 'print(input.state === "running" && input.initialSync && input.initialSync.completed ? String(Math.max(Number(input.lagTimeSeconds || 0), 0)) : "null")') || lag=null
fi
result "$(COPY=$copy LOG=$log LAG=$lag mongosh --nodb --quiet --norc --eval 'print(JSON.stringify({ copy: process.env.COPY, log: process.env.LOG, lagSeconds: process.env.LAG === "null" ? null : Number(process.env.LAG) }))')"
