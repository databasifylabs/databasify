@include('engines.sentinel._cli')
@include('engines.sentinel._node')
cli_try sentinel_cli SENTINEL SET main quorum {{ $quorum }}
require_replicas {{ $required }}
echo "Updated the Sentinel quorum."
