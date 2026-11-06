docker exec {{ $container }} databasify ctl logs --unit {{ $unit }} --lines {{ $lines }} --output short-iso 2>&1 </dev/null
