@include('engines._runtime')
set -euo pipefail
dpkg --purge @foreach ($packageNames as $packageName){!! $sh->arg($packageName) !!} @endforeach> /dev/null
@foreach ($packageNames as $packageName)
echo {!! $sh->arg("{$packageName} is removed.") !!}
@endforeach
