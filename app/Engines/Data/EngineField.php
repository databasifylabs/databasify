<?php

declare(strict_types=1);

namespace App\Engines\Data;

use App\Engines\Payload;

final readonly class EngineField
{
    /**
     * @param  list<string>  $options
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $kind,
        public string $default,
        public array $options,
        public ?string $unit,
        public ?float $min,
        public ?float $max,
        public ?string $pattern,
        public bool $restart,
        public bool $tuned,
    ) {}

    public static function fromPayload(Payload $payload): self
    {
        return new self(
            key: $payload->string('key'),
            label: $payload->string('label'),
            description: $payload->string('description'),
            kind: $payload->string('kind'),
            default: $payload->string('default'),
            options: $payload->strings('options'),
            unit: $payload->nullableString('unit'),
            min: $payload->nullableFloat('min'),
            max: $payload->nullableFloat('max'),
            pattern: $payload->nullableString('pattern'),
            restart: $payload->bool('restart'),
            tuned: $payload->bool('tuned'),
        );
    }

    /**
     * @return array{key: string, label: string, description: string, kind: string, default: string, options: list<string>, unit: ?string, min: ?float, max: ?float, pattern: ?string, restart: bool, tuned: bool}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'kind' => $this->kind,
            'default' => $this->default,
            'options' => $this->options,
            'unit' => $this->unit,
            'min' => $this->min,
            'max' => $this->max,
            'pattern' => $this->pattern,
            'restart' => $this->restart,
            'tuned' => $this->tuned,
        ];
    }
}
