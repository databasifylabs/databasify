<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use Illuminate\Support\Collection;

final readonly class SentinelReply
{
    public function __construct(private string $text) {}

    public function isEmpty(): bool
    {
        return $this->text === '';
    }

    public function fieldOf(string $name): ?string
    {
        return $this->fieldsOf($name)[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function fieldsOf(string $name): array
    {
        preg_match_all('/^'.preg_quote($name, '/').':(.*)$/m', $this->text, $matches);

        return $matches[1];
    }

    public function intOf(string $name): ?int
    {
        $value = $this->fieldOf($name);

        return $value !== null && preg_match('/^-?\d+$/D', $value) === 1 ? (int) $value : null;
    }

    public function megabytesOf(string $name): ?int
    {
        $bytes = $this->intOf($name);

        return $bytes === null ? null : intdiv($bytes, 1048576);
    }

    public function valueOf(string $key): ?string
    {
        return $this->valuesOf($key)[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function valuesOf(string $key): array
    {
        return array_values(collect(explode("\n", $this->text))
            ->chunk(2)
            ->filter(fn (Collection $pair): bool => $pair->first() === $key)
            ->map(fn (Collection $pair): string => $pair->values()->get(1, ''))
            ->all());
    }
}
