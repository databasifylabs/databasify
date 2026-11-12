@include('engines.mysql-family._mysql')

rows=$(my '' <<SQL
SHOW GLOBAL VARIABLES WHERE Variable_name IN ($NAMES)
SQL
)
result "$(jq -cRn '{values: ([inputs | select(. != "") | split("\t") | {key: (.[0] | ascii_downcase), value: (.[1:] | join("\t"))}] | from_entries)}' <<< "$rows")"
