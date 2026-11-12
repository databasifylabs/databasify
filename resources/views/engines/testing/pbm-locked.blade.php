@include('engines.mongodb._mongo')
@include('engines._write')
@include('engines.mongodb._pbm')
if pbm_locked config --file x; then
    result "{\"output\":\"$PBM_OUTPUT\"}"
else
    databasify_fail internal "$PBM_ERROR"
fi
