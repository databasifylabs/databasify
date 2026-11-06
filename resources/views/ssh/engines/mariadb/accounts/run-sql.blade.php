@include('ssh.engines.mariadb.helpers')
echo '{{ base64_encode($sql) }}' | msql_from
echo "{{ $summary }}"
