@include('engines.sentinel._cli')
ROOT=$TEST_ROOT
result "$(local_cli PING)"
