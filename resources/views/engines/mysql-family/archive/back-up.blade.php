@include('engines._runtime')
databasify ctl start --no-block {!! $sh->arg($engine->archiveUnits()['backup']) !!} > /dev/null 2>&1 || true
echo "Started a base backup."
