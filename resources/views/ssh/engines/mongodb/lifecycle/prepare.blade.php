@include('ssh.databases.helpers')
install -d -m 750 -o mongodb -g mongodb "$dir/data"
