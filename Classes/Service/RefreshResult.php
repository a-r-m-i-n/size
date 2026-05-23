<?php

declare(strict_types = 1);

namespace T3\Size\Service;

final readonly class RefreshResult
{
    public const STATUS_REFRESHED = 'refreshed';
    public const STATUS_LOCKED = 'locked';

    private function __construct(
        public string $status,
        public ?int $calculatedAt = null,
        public ?int $durationMs = null,
    ) {
    }

    public static function refreshed(int $calculatedAt, int $durationMs): self
    {
        return new self(self::STATUS_REFRESHED, $calculatedAt, $durationMs);
    }

    public static function locked(): self
    {
        return new self(self::STATUS_LOCKED);
    }

    public function wasRefreshed(): bool
    {
        return self::STATUS_REFRESHED === $this->status;
    }

    public function wasLocked(): bool
    {
        return self::STATUS_LOCKED === $this->status;
    }
}
