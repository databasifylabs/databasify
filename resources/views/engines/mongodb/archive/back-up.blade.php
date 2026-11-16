@include('engines._runtime')
databasify ctl start --no-block mongodb-backup > /dev/null 2>&1 || true
echo 'Started a backup.'
