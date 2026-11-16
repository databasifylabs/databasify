@include('engines.mongodb._mongo')
@if ($seed)
echo 'Bootstrapping…'
MONGO_TIMEOUT=300 mongo_js none > /dev/null <<'JS'
@include('engines.mongodb._initiate')
main(() => {
  initiate(@json($self));
  result({});
});
JS
@else
@include('engines.mongodb._join')
echo 'Joining…'
join_set
@endif
