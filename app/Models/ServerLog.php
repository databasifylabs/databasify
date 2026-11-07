<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Servers\ServerLogStatus;
use App\Events\ServerLogOutputReceived;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ServerLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * @property string $id
 * @property string $server_id
 * @property string|null $database_id
 * @property string|null $group_id
 * @property string|null $group_name
 * @property string $name
 * @property ServerLogStatus $status
 * @property string|null $output
 * @property int|null $exit_code
 * @property-read Server $server
 * @property-read Database|null $database
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['server_id', 'database_id', 'group_id', 'group_name', 'name', 'status', 'output', 'exit_code'])]
#[UseFactory(ServerLogFactory::class)]
class ServerLog extends Model
{
    /** @use HasFactory<ServerLogFactory> */
    use HasFactory, HasUuids, MassPrunable;

    public const int RETENTION_DAYS = 30;

    private const int FLUSH_BYTES = 16384;

    private const float FLUSH_SECONDS = 1.0;

    protected $attributes = ['status' => ServerLogStatus::Running->value];

    private string $unsaved = '';

    private float $savedAt = 0.0;

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<Database, $this>
     */
    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->startedBefore(now()->subDays(self::RETENTION_DAYS))->finished()->outsideRunningOperations();
    }

    public function write(string $chunk): void
    {
        $this->buffer($chunk);

        if (strlen($this->unsaved) >= self::FLUSH_BYTES || microtime(true) - $this->savedAt >= self::FLUSH_SECONDS) {
            $this->saveOutput();
        }
    }

    public function finish(int $exitCode): void
    {
        $this->saveOutput(['status' => $exitCode === 0 ? ServerLogStatus::Succeeded : ServerLogStatus::Failed, 'exit_code' => $exitCode]);
        $this->broadcast('');
    }

    public function fail(string $message): void
    {
        $this->buffer(self::noteOf($message));
        $this->saveOutput(['status' => ServerLogStatus::Failed]);
    }

    public function failBecauseOf(?Throwable $exception): void
    {
        $this->fail($exception?->getMessage() ?? 'the job stopped unexpectedly');
    }

    public static function noteOf(string $message): string
    {
        return "▸ {$message}".PHP_EOL;
    }

    public function isRunning(): bool
    {
        return $this->status === ServerLogStatus::Running;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function latestFirst(Builder $query): void
    {
        $query->latest()->orderByDesc('id');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function inCurrentProject(Builder $query): void
    {
        $query->where(fn (Builder $logs) => $logs->whereHas('server')->orWhereHas('database'));
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function running(Builder $query): void
    {
        $query->where('status', ServerLogStatus::Running);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function finished(Builder $query): void
    {
        $query->whereIn('status', [ServerLogStatus::Succeeded, ServerLogStatus::Failed]);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function outsideRunningOperations(Builder $query): void
    {
        $query->whereDoesntHave('database', fn (Builder $database): Builder => $database->whereNotNull('operation'));
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function startedBefore(Builder $query, CarbonInterface $moment): void
    {
        $query->where('created_at', '<', $moment);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function saveOutput(array $attributes = []): void
    {
        $this->update($this->unsaved === '' ? $attributes : [...$attributes, 'output' => $this->output.$this->unsaved]);
        $this->unsaved = '';
        $this->savedAt = microtime(true);
    }

    private function buffer(string $chunk): void
    {
        $this->broadcast($chunk);
        $this->unsaved .= $chunk;
    }

    private function broadcast(string $chunk): void
    {
        $offset = strlen($this->output ?? '') + strlen($this->unsaved);
        rescue(fn () => event(new ServerLogOutputReceived($this, $chunk, $offset)), report: false);
    }

    protected function casts(): array
    {
        return ['status' => ServerLogStatus::class];
    }
}
