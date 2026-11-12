@include('engines.postgres._psql')
unit_do restart "$(if clustered; then echo databasify-patroni; else echo databasify-postgres; fi)"
result '{}'
