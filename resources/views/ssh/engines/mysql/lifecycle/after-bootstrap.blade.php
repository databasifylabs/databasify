@include('ssh.engines.mysql.helpers')
echo '{{ base64_encode($sql) }}' | msql_from
echo "database {{ $schema }} and user {{ $username }} ready"
