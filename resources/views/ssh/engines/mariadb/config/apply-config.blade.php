@include('ssh.engines.mariadb.helpers')
@foreach ($sqlValues as $key => $value)
msql "SET GLOBAL {{ $key }} = {!! $value !!}" > /dev/null || { echo "could not set {{ $key }}"; exit 1; }
@endforeach
@foreach ($resets as $key)
msql "SET GLOBAL {{ $key }} = DEFAULT" > /dev/null || { echo "could not reset {{ $key }}"; exit 1; }
@endforeach
record_running_config
echo "settings applied"
