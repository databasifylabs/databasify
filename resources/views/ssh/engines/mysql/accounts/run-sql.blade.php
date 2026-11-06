@include('ssh.engines.mysql.helpers')
echo '{{ base64_encode($sql) }}' | msql_from
echo "{{ $summary }}"
