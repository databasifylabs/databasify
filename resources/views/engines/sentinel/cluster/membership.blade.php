@include('engines.sentinel._cli')
@include('engines.sentinel._node')
cli_try sentinel_cli SENTINEL SET main quorum "$QUORUM"
require_replicas "$REQUIRED"
echo "Updated the Sentinel quorum."
