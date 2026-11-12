@include('engines.mysql-family._mysql')
@include('engines.mysql-family._accounts')
users=$(my '' <<SQL
SELECT COALESCE(JSON_ARRAYAGG(User), JSON_ARRAY()) FROM mysql.db WHERE Host = '%' AND Db = $quoted_name
SQL
)
while IFS= read -r -d '' user; do
    revoke_on "$NAME" "$user"
done < <(each_of "$users")
my '' > /dev/null <<SQL
DROP DATABASE IF EXISTS $(my_name "$NAME")
SQL
result '{}'
