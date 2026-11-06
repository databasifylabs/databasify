@include('ssh.engines.mongodb.helpers')
if server_active; then
    mongo_js 'db.adminCommand({ rotateCertificates: 1 }).ok' > /dev/null
    echo "mongod loaded the new certificates"
else
    echo "mongod is not running, it loads the certificates when it starts"
fi
