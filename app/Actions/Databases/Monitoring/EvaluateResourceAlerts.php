<?php

declare(strict_types=1);

namespace App\Actions\Databases\Monitoring;

use App\Enums\Databases\ResourceMetric;
use App\Events\DatabaseAlertRaised;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\NodeAlert;
use App\Models\NodeMetricSample;
use Illuminate\Support\Collection;

final readonly class EvaluateResourceAlerts
{
    public const int THRESHOLD_PERCENT = 90;

    public const int SAMPLES = 5;

    public function handle(Database $database): void
    {
        $database->nodes()->with(['server', 'openAlerts'])->get()
            ->each(fn (DatabaseNode $node) => $this->evaluate($database, $node, $node->latestSamples(self::SAMPLES)));
    }

    /**
     * @param  Collection<int, NodeMetricSample>  $samples
     */
    private function evaluate(Database $database, DatabaseNode $node, Collection $samples): void
    {
        foreach (ResourceMetric::cases() as $metric) {
            $open = $node->openAlertFor($metric);

            if ($open instanceof NodeAlert) {
                $this->resolveIfRecovered($database, $open, $samples);

                continue;
            }

            $this->openIfSustained($database, $node, $metric, $samples);
        }
    }

    /**
     * @param  Collection<int, NodeMetricSample>  $samples
     */
    private function openIfSustained(Database $database, DatabaseNode $node, ResourceMetric $metric, Collection $samples): void
    {
        $values = $this->valuesOf($samples, $metric);

        if ($values->count() < self::SAMPLES || $values->contains(fn (float $value): bool => $value < self::THRESHOLD_PERCENT)) {
            return;
        }

        $node->raiseAlert($metric);
        $threshold = self::THRESHOLD_PERCENT;
        event(new DatabaseAlertRaised(
            $database,
            "{$node->displayName()} on {$database->name}: {$metric->label()} above {$threshold}%",
            "{$metric->label()} usage on {$node->displayName()} has stayed at or above {$threshold}% for the last {$values->count()} minutes (now {$values->first()}%).",
        ));
    }

    /**
     * @param  Collection<int, NodeMetricSample>  $samples
     */
    private function resolveIfRecovered(Database $database, NodeAlert $alert, Collection $samples): void
    {
        $latest = $this->valuesOf($samples, $alert->metric)->first();

        if ($latest === null || $latest >= self::THRESHOLD_PERCENT) {
            return;
        }

        $alert->resolve();
        event(new DatabaseAlertRaised(
            $database,
            "{$alert->node->displayName()} on {$database->name}: {$alert->metric->label()} back to normal",
            "{$alert->metric->label()} usage on {$alert->node->displayName()} dropped to {$latest}%.",
        ));
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
