@if ($patterns === '')
SET GLOBAL migration.replicate_wild_ignore_table = 'mysql.%';
@else
SET GLOBAL migration.replicate_wild_do_table = {!! $patterns !!};
@endif
