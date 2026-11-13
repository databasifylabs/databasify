@include('engines.mysql-family._mysql')
member=$(my '' <<SQL
SELECT MEMBER_ID FROM performance_schema.replication_group_members WHERE MEMBER_HOST = $(my_string "$TARGET")
SQL
)
if [ -z "$member" ]; then
    databasify_fail not_found "$TARGET is not a member of the group"
fi
my '' > /dev/null <<SQL
SELECT group_replication_set_as_primary($(my_string "$member"))
SQL
echo "Handed the primary over to $TARGET."
