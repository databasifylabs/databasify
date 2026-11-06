@if ($patterns === '')
CHANGE REPLICATION FILTER REPLICATE_WILD_IGNORE_TABLE = ('mysql.%', 'sys.%') FOR CHANNEL 'migration';
@else
CHANGE REPLICATION FILTER REPLICATE_WILD_DO_TABLE = ({!! $patterns !!}) FOR CHANNEL 'migration';
@endif
