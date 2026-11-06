<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Databases\PoolMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $database_id
 * @property string $name
 * @property string $schema
 * @property PoolMode $mode
 * @property int $size
 * @property-read Database $database
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'schema', 'mode', 'size'])]
class ConnectionPool extends Model
{
    use HasUuids;

    /**
     * @return BelongsTo<Database, $this>
     */
    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    protected function casts(): array
    {
        return [
            'mode' => PoolMode::class,
            'size' => 'integer',
        ];
    }
}
