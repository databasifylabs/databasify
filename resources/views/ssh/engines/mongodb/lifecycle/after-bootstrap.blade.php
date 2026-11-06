@include('ssh.engines.mongodb.helpers')
echo '{{ base64_encode($sql) }}' | mongo_from > /dev/null
echo "database {{ $schema }} and user {{ $username }} ready"
