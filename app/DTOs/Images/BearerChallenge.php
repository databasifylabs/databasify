<?php

declare(strict_types=1);

namespace App\DTOs\Images;

final readonly class BearerChallenge
{
    public function __construct(
        public string $realm,
        public ?string $service,
    ) {}

    public static function from(string $header): ?self
    {
        if (stripos(trim($header), 'Bearer ') !== 0) {
            return null;
        }

        preg_match_all('/(\w+)="([^"]*)"/', $header, $matches, PREG_SET_ORDER);
        $parameters = collect($matches)->mapWithKeys(fn (array $match): array => [strtolower($match[1]) => $match[2]]);
        $realm = $parameters->get('realm');

        return $realm === null ? null : new self($realm, $parameters->get('service'));
    }

    /**
     * @return array<string, string>
     */
    public function queryFor(string $scope): array
    {
        return array_filter(['service' => $this->service, 'scope' => $scope], fn (?string $value): bool => $value !== null);
    }
}
