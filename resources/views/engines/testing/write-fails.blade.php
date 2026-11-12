@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
ROOT=$TEST_ROOT
printf 'x' | write_file "$ROOT/missing/file" 600 false || true
result '{"written":"unnoticed"}'
