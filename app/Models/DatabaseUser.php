<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * @property string $id
 * @property string $database_id
 * @property string $name
 * @property string $password
 * @property string|null $password_encryption
 * @property-read Database $database
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'password', 'password_encryption'])]
#[Hidden(['password'])]
class DatabaseUser extends Model
{
    use HasUuids;

    /**
     * @return BelongsTo<Database, $this>
     */
    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    public function isDefault(): bool
    {
        return $this->name === $this->database->settings->defaultUsername();
    }

    public function ensureDeletable(): void
    {
        if ($this->isDefault()) {
            throw ValidationException::withMessages(['user' => "{$this->name} is the default user and can't be deleted."]);
        }
    }

    public function hasEncryptionChoice(): bool
    {
        return count($this->database->passwordEncryptions()) > 1;
    }

    protected function casts(): array
    {
        return ['password' => 'encrypted'];
    }
}
