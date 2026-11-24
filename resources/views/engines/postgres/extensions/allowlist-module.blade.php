@include('engines._runtime')
if [ -f {!! $sh->arg("/usr/lib/postgresql/{$line}/lib/plugins/pgextwlist.so") !!} ]; then
    result '{"installed":true}'
else
    result '{"installed":false}'
fi
