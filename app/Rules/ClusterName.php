<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Database;
use App\Models\DatabaseNode;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

final readonly class ClusterName implements ValidationRule
{
    public const string FORMAT = 'Names must be in lowercase, start with a letter, end with a letter or number, be between 3 and 43 characters long, and contain only letters, numbers, or dashes (-).';

    private const string PATTERN = '/^[a-z][a-z0-9-]{1,41}[a-z0-9]$/';

    private const array RESERVED_PREFIXES = ['private-', 'replica-'];

    private const string NODE_CERTIFICATE_NAME = '/^node-[0-9a-f-]+$/';

    public function __construct(
        private ?DatabaseNode $exceptNode = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail(self::FORMAT);

            return;
        }

        if (Str::startsWith($value, self::RESERVED_PREFIXES)) {
            $fail('Names can\'t start with private- or replica-, those prefixes are used for private and replica hostnames.');

            return;
        }

        if (preg_match(self::NODE_CERTIFICATE_NAME, $value) === 1) {
            $fail('Names like node-12 are reserved for node certificates.');

            return;
        }

        if ($this->isTaken($value)) {
            $fail("{$value} is already used by another database or read-only node.");
        }
    }

    private function isTaken(string $name): bool
    {
        return Database::query()->withoutProject()->where('name', $name)->exists()
            || DatabaseNode::query()->where('name', $name)->whereKeyNot($this->exceptNode?->id)->exists();
    }
}
