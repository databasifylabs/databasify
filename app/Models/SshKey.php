<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 * @property string $public_key
 * @property string $private_key
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'public_key', 'private_key'])]
#[Hidden(['private_key'])]
class SshKey extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
        ];
    }
}
