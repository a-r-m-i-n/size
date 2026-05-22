<?php

declare(strict_types=1);

namespace T3\Size\Event;

final class BeforeSizeOverviewSnapshotStoredEvent
{
    /**
     * @param array<string, mixed> $overview
     */
    public function __construct(
        private array $overview,
        private readonly int $calculatedAt,
        private readonly int $durationMs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getOverview(): array
    {
        return $this->overview;
    }

    /**
     * @param array<string, mixed> $overview
     */
    public function setOverview(array $overview): void
    {
        $this->overview = $overview;
    }

    public function getCalculatedAt(): int
    {
        return $this->calculatedAt;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }
}
