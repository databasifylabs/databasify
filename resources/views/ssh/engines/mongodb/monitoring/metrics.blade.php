mongo_js 'const status = db.serverStatus(); const ops = status.opcounters; print("threads_connected=" + status.connections.current); print("questions=" + (ops.insert + ops.query + ops.update + ops.delete + ops.getmore + ops.command)); print("uptime=" + status.uptime); print("data_mb=" + Math.round(db.adminCommand({ listDatabases: 1 }).totalSize / 1048576))'
own_role | sed 's/^/state=/'
