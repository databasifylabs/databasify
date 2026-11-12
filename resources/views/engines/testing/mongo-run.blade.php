@include('engines.mongodb._mongo')
mongo_js root <<'JS'
main(() => result({ said: env.WORD }));
JS
