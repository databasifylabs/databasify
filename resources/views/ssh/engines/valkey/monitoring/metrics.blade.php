vcli INFO | awk -F ':' '/^(role|connected_clients|used_memory|instantaneous_ops_per_sec|uptime_in_seconds|evicted_keys|keyspace_hits|keyspace_misses):/ { print $1 "=" $2 }'
vcli INFO keyspace | awk -F '[=,:]' '/^db[0-9]+:/ { total += $3 } END { print "keys=" total + 0 }'
