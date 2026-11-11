docker run --rm --entrypoint cat {{ $image }} /databasify > /usr/local/bin/databasify.new
if cmp -s /usr/local/bin/databasify.new /usr/local/bin/databasify; then
    rm /usr/local/bin/databasify.new
else
    chmod 755 /usr/local/bin/databasify.new && mv /usr/local/bin/databasify.new /usr/local/bin/databasify
fi
