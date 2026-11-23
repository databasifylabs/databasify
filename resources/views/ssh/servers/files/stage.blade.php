set -e
cp {!! $sh->arg($remotePath) !!} {!! $sh->arg($staging) !!}
chown {!! $sh->arg($user) !!} {!! $sh->arg($staging) !!}
