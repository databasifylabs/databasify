container=$(docker create {{ $image }})
docker cp "$container:/databasify" /usr/local/bin/databasify.new || { docker rm "$container" > /dev/null; exit 1; }
docker rm "$container" > /dev/null
if cmp -s /usr/local/bin/databasify.new /usr/local/bin/databasify; then
    rm /usr/local/bin/databasify.new
else
    chmod 755 /usr/local/bin/databasify.new && mv /usr/local/bin/databasify.new /usr/local/bin/databasify
fi
