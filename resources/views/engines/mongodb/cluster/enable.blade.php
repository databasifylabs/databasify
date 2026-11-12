@include('engines.mongodb._mongo')
@include('engines.mongodb._join')
if [ "$SEED" = true ]; then
    phase 'bootstrapping'
    initiate_seed
    exit 0
fi
phase 'joining'
join_set
