[ -d /srv/mongodb/data ] || { echo 'the data directory is missing, the deploy step creates it'; exit 0; }; exec mongod --config /srv/mongodb/conf/mongod.conf
