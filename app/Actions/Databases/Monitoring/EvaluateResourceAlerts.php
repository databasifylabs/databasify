<?php

declare(strict_types=1);

namespace App\Actions\Databases\Monitoring;

use App\Enums\Databases\ResourceMetric;
use App\Events\DatabaseAlertRaised;
use App\Models\Database;
use App\Models\NodeAlert;
use App\Models\NodeMetricSample;
use Illuminate\Support\Collection;

final readonly class EvaluateResourceAlerts
{
    public const int THRESHOLD_PERCENT = 90;

    public const int SAMPLES = 5;

    public function handle(Database $database): void
    {
        $threshold = self::THRESHOLD_PERCENT;

        foreach ($database->nodes()->with(['server', 'openAlerts'])->get() as $node) {
            $samples = $node->latestSamples(self::SAMPLES);

            foreach (ResourceMetric::cases() as $metric) {
                $values = $this->valuesOf($samples, $metric);
                $open = $node->openAlertFor($metric);

                if ($open instanceof NodeAlert) {
                    $latest = $values->first();

                    if ($latest !== null && $latest < $threshold) {
                        $open->resolve();
                        event(new DatabaseAlertRaised(
                            $database,
                            "{$node->displayName()} on {$database->name}: {$metric->label()} back to normal",
                            "{$metric->label()} usage on {$node->displayName()} dropped to {$latest}%.",
                        ));
                    }

                    continue;
                }

                if ($values->count() < self::SAMPLES || $values->contains(fn (float $value): bool => $value < $threshold)) {
                    continue;
                }

                $node->raiseAlert($metric);
                event(new DatabaseAlertRaised(
                    $database,
                    "{$node->displayName()} on {$database->name}: {$metric->label()} above {$threshold}%",
                    "{$metric->label()} usage on {$node->displayName()} has stayed at or above {$threshold}% for the last {$values->count()} minutes (now {$values->first()}%).",
                ));
            }
        }
    }

    /**
     * @param  Collection<int, NodeMetricSample>  $samples
     * @return Collection<int, float>
     */
    private function valuesOf(Collection $samples, ResourceMetric $metric): Collection
    {
        return $samples->map(fn (NodeMetricSample $sample): ?float => $sample->valueOf($metric))
            ->filter(fn (?float $value): bool => $value !== null)
            ->values();
    }
}
