<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Databases\ResourceMetric;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * @property string $id
 * @property string $database_node_id
 * @property CarbonImmutable $sampled_at
 * @property float|null $cpu_percent
 * @property float|null $load_1m
 * @property float|null $memory_percent
 * @property float|null $disk_percent
 * @property int|null $connections
 * @property float|null $ops_per_second
 * @property int|null $query_total
 * @property-read int|null $bucket_start
 * @property-read DatabaseNode $node
 */
#[Fillable(['database_node_id', 'sampled_at', 'cpu_percent', 'load_1m', 'memory_percent', 'disk_percent', 'connections', 'ops_per_second', 'query_total'])]
#[WithoutTimestamps]
class NodeMetricSample extends Model
{
    use HasUuids, MassPrunable;

    public const int RETENTION_DAYS = 7;

    private const array AVERAGED_METRICS = ['cpu_percent', 'load_1m', 'memory_percent', 'disk_percent', 'connections', 'ops_per_second'];

    /**
     * @return BelongsTo<DatabaseNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(DatabaseNode::class, 'database_node_id');
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->sampledBefore(now()->subDays(self::RETENTION_DAYS));
    }

    public function valueOf(ResourceMetric $metric): ?float
    {
        return match ($metric) {
            ResourceMetric::Cpu => $this->cpu_percent,
            ResourceMetric::Memory => $this->memory_percent,
            ResourceMetric::Disk => $this->disk_percent,
        };
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function sampledBefore(Builder $query, CarbonInterface $moment): void
    {
        $query->where('sampled_at', '<', $moment);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function since(Builder $query, CarbonInterface $moment): void
    {
        $query->where('sampled_at', '>=', $moment);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function averagedPerNodeIn(Builder $query, int $bucketSeconds): void
    {
        $averages = array_map(self::averageOf(...), self::AVERAGED_METRICS);

        $query->withCasts(['bucket_start' => 'integer', 'connections' => 'float'])
            ->select('database_node_id')
            ->selectRaw("{$this->bucketStartExpressionFor($query)} as bucket_start", [$bucketSeconds, $bucketSeconds])
            ->addSelect($averages)
            ->groupBy('bucket_start', 'database_node_id')
            ->orderBy('bucket_start');
    }

    /**
     * @param  literal-string  $metric
     */
    private static function averageOf(string $metric): Expression
    {
        return DB::raw("AVG({$metric}) as {$metric}");
    }

    /**
     * @param  Builder<self>  $query
     * @return literal-string
     */
    private function bucketStartExpressionFor(Builder $query): string
    {
        return match ($query->getModel()->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => 'CAST(FLOOR(TIMESTAMPDIFF(SECOND, \'1970-01-01 00:00:00\', sampled_at) / ?) * ? AS SIGNED)',
            'pgsql' => 'CAST(FLOOR(EXTRACT(EPOCH FROM sampled_at) / ?) * ? AS BIGINT)',
            default => "CAST(strftime('%s', sampled_at) AS INTEGER) / ? * ?",
        };
    }

    protected function casts(): array
    {
        return [
            'sampled_at' => 'datetime',
            'cpu_percent' => 'float',
            'load_1m' => 'float',
            'memory_percent' => 'float',
            'disk_percent' => 'float',
            'connections' => 'integer',
            'ops_per_second' => 'float',
            'query_total' => 'integer',
        ];
    }
}
