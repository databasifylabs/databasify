@include('engines.mongodb._mongo')
@include('engines.mongodb._join')
if [ "$SEED" = true ]; then
    echo 'Bootstrapping…'
    initiate_seed
    exit 0
fi
echo 'Joining…'
join_set
