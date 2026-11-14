[client]
user=databasify_agent
password={!! $password !!}
socket={!! $home !!}/run/mysqld.sock

[xtrabackup]
datadir={!! $home !!}/data
