@include('engines.mysql-family._mysql')
my '' > /dev/null <<SQL
SET GLOBAL group_replication_group_seeds = $(my_string "$SEEDS")
SQL
echo "Updated the group seeds to $SEEDS."
