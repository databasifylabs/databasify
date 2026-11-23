printf '%s' {!! $sh->arg($payload) !!} | base64 -d | databasify {!! $command !!} 2>/dev/null
