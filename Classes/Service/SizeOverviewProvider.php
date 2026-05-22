<?php

declare(strict_types=1);

namespace T3\Size\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

final class SizeOverviewProvider
{
    private ?array $overviewCache = null;
    private ?array $contextCache = null;

    public function __construct(
        private readonly SizeOverviewSnapshotStorage $snapshotStorage,
        private readonly SizeOverviewRefreshService $refreshService,
        private readonly StorageUsageNotificationRegistry $notificationRegistry,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getOverview(): array
    {
        if ($this->overviewCache !== null) {
            return $this->overviewCache;
        }

        $snapshot = $this->snapshotStorage->getSnapshot();
        $this->overviewCache = is_array($snapshot['overview'] ?? null)
            ? $snapshot['overview']
            : $this->createEmptyOverview();

        return $this->overviewCache;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOverviewContext(): array
    {
        if ($this->contextCache !== null) {
            return $this->contextCache;
        }

        $snapshot = $this->snapshotStorage->getSnapshot();
        $calculatedAt = is_array($snapshot) ? (int)$snapshot['calculatedAt'] : null;
        $durationMs = is_array($snapshot) ? (int)$snapshot['durationMs'] : null;
        $lastNotificationCheck = $this->notificationRegistry->getLastCheck();

        $this->contextCache = [
            'overview' => $this->getOverview(),
            'lastUpdatedTimestamp' => $calculatedAt,
            'lastUpdatedLabel' => $calculatedAt !== null ? date('Y-m-d H:i:s', $calculatedAt) : null,
            'lastUpdatedAgeLabel' => $this->formatAgeLabel($calculatedAt),
            'lastRuntimeLabel' => $this->formatRuntimeLabel($durationMs),
            'lastNotificationStatusLabel' => $this->formatLastNotificationStatusLabel($calculatedAt, $lastNotificationCheck),
            'hasSnapshot' => $snapshot !== null,
            'isRefreshRunning' => $this->refreshService->isRefreshRunning(),
            'isAdminUser' => $this->isAdminUser(),
        ];

        return $this->contextCache;
    }

    public function isAdminUser(): bool
    {
        return $this->getBackendUser()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    private function createEmptyOverview(): array
    {
        $notAvailable = $this->translate('notAvailable');

        return [
            'code' => [
                'vendor' => $this->createEmptyValue($notAvailable),
                'extensions' => $this->createEmptyValue($notAvailable),
                'dependencies' => $this->createEmptyValue($notAvailable),
                'total' => $this->createEmptyValue($notAvailable),
            ],
            'misc' => $this->createEmptyValue($notAvailable),
            'chart' => [
                'categories' => [
                    $this->createEmptyChartCategory('media', 'section.fileadmin', 'size-storage-color-media', $notAvailable),
                    $this->createEmptyChartCategory('database', 'section.database', 'size-storage-color-database', $notAvailable),
                    $this->createEmptyChartCategory('code', 'section.code', 'size-storage-color-code', $notAvailable),
                    $this->createEmptyChartCategory('misc', 'section.misc', 'size-storage-color-misc', $notAvailable),
                ],
                'maximumBytes' => null,
                'referenceBytes' => 0,
                'totalPercentage' => 0.0,
                'availableBytes' => null,
                'availablePercentage' => null,
                'availableLabel' => null,
                'showAvailableSegment' => false,
                'isMaximumConfigured' => false,
            ],
            'storages' => [
                'items' => [],
                'total' => [
                    'bytes' => null,
                    'label' => $notAvailable,
                    'available' => false,
                ],
            ],
            'mediaBreakdown' => [
                'storages' => [],
            ],
            'mediaBreakdownTotal' => $this->createEmptyValue($notAvailable),
            'database' => [
                'connections' => [],
                'total' => [
                    'bytes' => null,
                    'label' => $notAvailable,
                    'available' => false,
                ],
            ],
            'total' => [
                'bytes' => 0,
                'label' => $notAvailable,
                'displayLabel' => $notAvailable,
                'highlightClass' => 'text-muted',
                'badgeClass' => '',
            ],
        ];
    }

    /**
     * @return array{bytes: int|null, label: string}
     */
    private function createEmptyValue(string $label): array
    {
        return [
            'bytes' => null,
            'label' => $label,
        ];
    }

    /**
     * @return array{identifier: string, label: string, bytes: int, formattedBytes: string, percentage: float, colorClass: string}
     */
    private function createEmptyChartCategory(string $identifier, string $labelKey, string $colorClass, string $notAvailable): array
    {
        return [
            'identifier' => $identifier,
            'label' => $this->translate($labelKey),
            'bytes' => 0,
            'formattedBytes' => $notAvailable,
            'percentage' => 0.0,
            'colorClass' => $colorClass,
        ];
    }

    private function formatAgeLabel(?int $timestamp): string
    {
        if ($timestamp === null) {
            return $this->translate('module.storageStatistics.notMeasuredYet');
        }

        $seconds = max(0, time() - $timestamp);
        if ($seconds < 60) {
            return $this->translate('module.storageStatistics.justNow');
        }
        if ($seconds < 3600) {
            $minutes = (int)floor($seconds / 60);
            return sprintf($this->translate('module.storageStatistics.minutesAgo'), $minutes);
        }
        if ($seconds < 86400) {
            $hours = (int)floor($seconds / 3600);
            return sprintf($this->translate('module.storageStatistics.hoursAgo'), $hours);
        }

        $days = (int)floor($seconds / 86400);
        return sprintf($this->translate('module.storageStatistics.daysAgo'), $days);
    }

    private function formatRuntimeLabel(?int $durationMs): string
    {
        if ($durationMs === null) {
            return $this->translate('module.storageStatistics.notMeasuredYet');
        }
        if ($durationMs < 1000) {
            return $durationMs . ' ms';
        }

        return number_format($durationMs / 1000, 2, '.', '') . ' s';
    }

    /**
     * @param array{calculatedAt: int, warningRecipients: list<string>, fullRecipients: list<string>}|null $lastNotificationCheck
     */
    private function formatLastNotificationStatusLabel(?int $snapshotCalculatedAt, ?array $lastNotificationCheck): ?string
    {
        if ($snapshotCalculatedAt === null) {
            return null;
        }

        if ($lastNotificationCheck === null || $lastNotificationCheck['calculatedAt'] !== $snapshotCalculatedAt) {
            return $this->translate('module.storageStatistics.notification.none');
        }

        $warningRecipients = $lastNotificationCheck['warningRecipients'];
        $fullRecipients = $lastNotificationCheck['fullRecipients'];

        if ($warningRecipients !== [] && $fullRecipients !== []) {
            return sprintf(
                $this->translate('module.storageStatistics.notification.warningAndFull'),
                implode(', ', $warningRecipients),
                implode(', ', $fullRecipients),
            );
        }
        if ($warningRecipients !== []) {
            return sprintf(
                $this->translate('module.storageStatistics.notification.warning'),
                implode(', ', $warningRecipients),
            );
        }
        if ($fullRecipients !== []) {
            return sprintf(
                $this->translate('module.storageStatistics.notification.full'),
                implode(', ', $fullRecipients),
            );
        }

        return $this->translate('module.storageStatistics.notification.none');
    }

    private function translate(string $key): string
    {
        return $this->languageServiceFactory
            ->createFromUserPreferences($this->getBackendUser())
            ->sL('LLL:EXT:size/Resources/Private/Language/locallang.xlf:' . $key) ?: $key;
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }
}
