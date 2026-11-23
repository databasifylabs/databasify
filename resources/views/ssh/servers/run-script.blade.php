@php($script = $sh->arg($path))
{!! $sudo ? 'sudo -n ' : '' !!}bash -c {!! $sh->arg("bash {$script}; status=\$?; rm -f {$script}; exit \$status") !!}
