@include('ssh.databases.helpers')
@foreach ($files as $file)
@continue(! str_starts_with($file->path, "{$directory}/conf/pgbouncer"))
@include('ssh.databases.files.put', ['owner' => $file->owner])
@endforeach
databasify ctl reload databasify-pgbouncer
echo "connection pools updated"
