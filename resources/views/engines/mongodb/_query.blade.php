@include('engines.mongodb._mongo')
MONGO_TIMEOUT={{ $timeout }}
printf '%s' {!! $sh->arg($query) !!} | base64 -d | mongo_js {!! $sh->arg($auth) !!}
