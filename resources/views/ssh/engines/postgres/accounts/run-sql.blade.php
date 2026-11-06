@include('ssh.engines.postgres.helpers')
echo '{{ base64_encode($sql) }}' | pg_from > /dev/null
echo "{{ $summary }}"
