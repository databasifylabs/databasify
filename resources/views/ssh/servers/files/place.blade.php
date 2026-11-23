set -e
mkdir -p {!! $sh->arg(dirname($remotePath)) !!}
mv {!! $sh->arg($staging) !!} {!! $sh->arg("{$remotePath}.part") !!}
mv {!! $sh->arg("{$remotePath}.part") !!} {!! $sh->arg($remotePath) !!}
