@include('engines._runtime')
result "$(jq -cn --arg architecture "$(dpkg --print-architecture)" '{architecture: $architecture}')"
