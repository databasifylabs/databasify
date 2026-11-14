@include('engines.mongodb._mongo')
@include('engines.mongodb._units')
unit_do start mongod
if archived; then
    unit_do start pbm-agent
fi
echo 'Started MongoDB.'
