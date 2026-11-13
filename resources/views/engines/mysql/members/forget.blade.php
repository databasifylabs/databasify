@include('engines.mysql-family._mysql')
current=$(my '' <<'SQL'
@verbatim
SELECT @@group_replication_group_seeds
@endverbatim
SQL
)
seeds=$(tr ',' '\n' <<< "$current" | awk -v gone="$SEED" 'NF && $0 != gone && $0 != previous { printf "%s%s", separator, $0; separator = ","; previous = $0 }')
my '' > /dev/null <<SQL
SET GLOBAL group_replication_group_seeds = $(my_string "$seeds")
SQL
echo "Forgot $SEED."
