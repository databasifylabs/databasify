command -v databasify >/dev/null 2>&1 && exit 0
docker run --rm --entrypoint cat {{ $image }} /usr/local/bin/databasify > /usr/local/bin/databasify.new
chmod 755 /usr/local/bin/databasify.new && mv /usr/local/bin/databasify.new /usr/local/bin/databasify
