<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

use Illuminate\Support\Collection;

final readonly class RestorableWindow
{
    public function __construct(public ?int $earliest, public ?int $latest) {}

    /**
     * @param  list<int>  $snapshots
     * @param  list<OplogRange>  $ranges
     */
    public static function of(array $snapshots, array $ranges): self
    {
        if ($snapshots === []) {
            return new self(null, null);
        }

        $newest = max($snapshots);
        $timeline = self::timelinesOf($ranges)
            ->filter(fn (OplogRange $range): bool => $range->reaches($newest) && self::oldestBaseIn($range, $snapshots) !== null)
            ->sortByDesc(fn (OplogRange $range): int => $range->end)
            ->first();

        if ($timeline === null) {
            return new self($newest, $newest);
        }

        return new self(self::oldestBaseIn($timeline, $snapshots), max($timeline->end, $newest));
    }

    /**
     * @param  list<OplogRange>  $ranges
     * @return Collection<int, OplogRange>
     */
    private static function timelinesOf(array $ranges): Collection
    {
        usort($ranges, fn (OplogRange $earlier, OplogRange $later): int => $earlier->start <=> $later->start);
        $timelines = [];

        foreach ($ranges as $range) {
            $last = array_key_last($timelines);

            if ($last !== null && $timelines[$last]->continuedBy($range)) {
                $timelines[$last] = $timelines[$last]->joinedWith($range);

                continue;
            }

            $timelines[] = $range;
        }

        return collect($timelines);
    }

    /**
     * @param  list<int>  $snapshots
     */
    private static function oldestBaseIn(OplogRange $timeline, array $snapshots): ?int
    {
        $bases = array_filter($snapshots, $timeline->startsFrom(...));

        return $bases === [] ? null : min($bases);
    }
}
