<?php

declare(strict_types = 1);

namespace T3\Size\Service;

use TYPO3\CMS\Core\Registry;

final readonly class SizeOverviewSnapshotStorage
{
    private const REGISTRY_NAMESPACE = 'size';
    private const REGISTRY_KEY = 'overview_snapshot';

    public function __construct(
        private Registry $registry,
    ) {
    }

    /**
     * @return array{overview: array<string, mixed>, calculatedAt: int, durationMs: int}|null
     */
    public function getSnapshot(): ?array
    {
        $snapshot = $this->registry->get(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY);
        if (!is_array($snapshot)) {
            return null;
        }
        if (!isset($snapshot['overview'], $snapshot['calculatedAt'], $snapshot['durationMs'])) {
            return null;
        }
        if (!is_array($snapshot['overview'])) {
            return null;
        }

        return [
            'overview' => $snapshot['overview'],
            'calculatedAt' => (int)$snapshot['calculatedAt'],
            'durationMs' => (int)$snapshot['durationMs'],
        ];
    }

    /**
     * @param array<string, mixed> $overview
     */
    public function storeSnapshot(array $overview, int $calculatedAt, int $durationMs): void
    {
        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, [
            'overview' => $overview,
            'calculatedAt' => $calculatedAt,
            'durationMs' => $durationMs,
        ]);
    }

    public function removeSnapshot(): void
    {
        $this->registry->remove(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY);
    }
}
