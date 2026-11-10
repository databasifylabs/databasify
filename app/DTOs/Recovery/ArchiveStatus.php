<?php

declare(strict_types=1);

namespace App\DTOs\Recovery;

use App\Engines\Responses\ArchiveStatus as EngineArchiveStatus;
use App\Enums\Recovery\ArchiveHealth;
use App\Support\Ssh\KeyValueOutput;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final readonly class ArchiveStatus
{
    public const int STALE_AFTER_MINUTES = 15;

    public function __construct(
        public int $backups,
        public ?CarbonImmutable $earliest,
        public ?CarbonImmutable $latest,
        public ?CarbonImmutable $lastBackup,
        public ?string $problem,
    ) {}

    public static function fromEngine(EngineArchiveStatus $status): self
    {
        return new self(
            backups: $status->backups,
            earliest: self::timeOf($status->earliest),
            latest: self::timeOf($status->latest),
            lastBackup: self::timeOf($status->lastBackup),
            problem: $status->problem,
        );
    }

    public static function fromOutput(string $output): self
    {
        $values = KeyValueOutput::parse($output);

        return new self(
            backups: (int) ($values['backups'] ?? 0),
            earliest: self::momentOf($values['earliest'] ?? ''),
            latest: self::momentOf($values['latest'] ?? ''),
            lastBackup: self::momentOf($values['last_backup'] ?? ''),
            problem: ($values['problem'] ?? '') === '' ? null : $values['problem'],
        );
    }

    public static function unreadable(string $reason): self
    {
        return new self(0, null, null, null, $reason);
    }

    public function keepingLatestOf(?CarbonInterface $previous): self
    {
        if ($previous === null || ($this->latest !== null && $this->latest->greaterThanOrEqualTo($previous))) {
            return $this;
        }

        return clone ($this, ['latest' => CarbonImmutable::instance($previous)]);
    }

    public function healthAt(CarbonInterface $now): ArchiveHealth
    {
        if ($this->problem !== null || $this->isStaleAt($now)) {
            return ArchiveHealth::Failing;
        }

        return $this->backups === 0 ? ArchiveHealth::Pending : ArchiveHealth::Healthy;
    }

    /**
     * @return array{health: ArchiveHealth, backups_count: int, earliest_restorable_at: ?CarbonImmutable, latest_restorable_at: ?CarbonImmutable, last_backup_at: ?CarbonImmutable, problem: ?string, checked_at: CarbonInterface}
     */
    public function toRow(CarbonInterface $now): array
    {
        return [
            'health' => $this->healthAt($now),
            'backups_count' => $this->backups,
            'earliest_restorable_at' => $this->earliest,
            'latest_restorable_at' => $this->latest,
            'last_backup_at' => $this->lastBackup,
            'problem' => $this->problemAt($now),
            'checked_at' => $now,
        ];
    }

    private function problemAt(CarbonInterface $now): ?string
    {
        if ($this->problem !== null) {
            return $this->problem;
        }

        if (! $this->isStaleAt($now)) {
            return null;
        }

        return 'Nothing reached the archive in the last '.self::STALE_AFTER_MINUTES.' minutes.';
    }

    private function isStaleAt(CarbonInterface $now): bool
    {
        return $this->backups > 0 && ($this->latest === null || $this->latest->diffInMinutes($now) > self::STALE_AFTER_MINUTES);
    }

    private static function timeOf(?string $time): ?CarbonImmutable
    {
        return $time === null ? null : CarbonImmutable::parse($time);
    }

    private static function momentOf(string $epoch): ?CarbonImmutable
    {
        return ctype_digit($epoch) && (int) $epoch > 0 ? CarbonImmutable::createFromTimestampUTC((int) $epoch) : null;
    }
}
