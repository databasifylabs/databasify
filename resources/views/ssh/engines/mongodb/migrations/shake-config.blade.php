conf.version = 14
id = databasify-migration
master_quorum = false
sync_mode = {{ $continuous ? 'all' : 'full' }}
mongo_urls = {!! $sourceUri !!}
@if ($sourceTls)
mongo_ssl_root_ca_file = {{ $caFile }}
@endif
mongo_connect_mode = primary
tunnel = direct
tunnel.address = {!! $targetUri !!}
tunnel.mongo_ssl_root_ca_file = {{ $caFile }}
tunnel.message = raw
incr_sync.mongo_fetch_method = oplog
@if ($databases !== '')
filter.namespace.white = {{ $databases }}
@endif
filter.pass.special.db = admin
checkpoint.storage = database
checkpoint.storage.url = {!! $targetUri !!}
checkpoint.storage.url.mongo_ssl_root_ca_file = {{ $caFile }}
checkpoint.storage.db = {{ $checkpointDatabase }}
checkpoint.storage.collection = checkpoint
full_sync.collection_exist_drop = true
full_sync.create_index = background
full_sync.executor.insert_on_dup_update = true
full_sync.http_port = 9101
incr_sync.http_port = 9100
system_profile_port = 9200
log.level = info
log.dir = {{ $stateDirectory }}/logs
log.file = collector.log
log.flush = true
