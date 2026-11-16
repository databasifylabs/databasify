@include('engines._runtime')
unit_do start mongod
@if ($agent)
unit_do start pbm-agent
@endif
echo 'Started MongoDB.'
