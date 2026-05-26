<?php

declare(strict_types = 1);

namespace T3\Size\Service;

use T3\Size\Localization\BackendLocalizationHelper;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class SizeOverviewProvider
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $overviewCache = null;
    /**
     * @var array<string, mixed>|null
     */
    private ?array $contextCache = null;

    public function __construct(
        private readonly SizeOverviewSnapshotStorage $snapshotStorage,
        private readonly SizeOverviewRefreshService $refreshService,
        private readonly StorageStatisticsHistoryService $historyService,
        private readonly StorageUsageNotificationRegistry $notificationRegistry,
        private readonly BackendLocalizationHelper $backendLocalizationHelper,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getOverview(): array
    {
        if (null !== $this->overviewCache) {
            return $this->overviewCache;
        }

        $snapshot = $this->snapshotStorage->getSnapshot();
        $overview = is_array($snapshot['overview'] ?? null)
            ? $snapshot['overview']
            : $this->createEmptyOverview();
        $overview = $this->normalizeChartViewData($overview);
        $this->overviewCache = $this->enrichOverviewWithHistory($overview);

        return $this->overviewCache;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOverviewContext(): array
    {
        if (null !== $this->contextCache) {
            return $this->contextCache;
        }

        $snapshot = $this->snapshotStorage->getSnapshot();
        $calculatedAt = is_array($snapshot) ? (int)$snapshot['calculatedAt'] : null;
        $durationMs = is_array($snapshot) ? (int)$snapshot['durationMs'] : null;
        $lastNotificationCheck = $this->notificationRegistry->getLastCheck();

        $this->contextCache = [
            'overview' => $this->getOverview(),
            'lastUpdatedTimestamp' => $calculatedAt,
            'lastUpdatedLabel' => null !== $calculatedAt ? date('Y-m-d H:i:s', $calculatedAt) : null,
            'lastUpdatedAgeLabel' => $this->formatAgeLabel($calculatedAt),
            'lastRuntimeLabel' => $this->formatRuntimeLabel($durationMs),
            'lastNotificationStatusLabel' => $this->formatLastNotificationStatusLabel($calculatedAt, $lastNotificationCheck),
            'hasSnapshot' => null !== $snapshot,
            'isRefreshRunning' => $this->refreshService->isRefreshRunning(),
            'isAdminUser' => $this->isAdminUser(),
            'historyEnabled' => $this->historyService->isHistoryEnabled(),
        ];

        return $this->contextCache;
    }

    public function isAdminUser(): bool
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin();
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
                'total' => [
                    ...$this->createEmptyValue($notAvailable),
                    'summaryLabel' => $notAvailable,
                ],
            ],
            'misc' => [
                'rows' => [],
                'total' => [
                    ...$this->createEmptyValue($notAvailable),
                    'summaryLabel' => $notAvailable,
                ],
            ],
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
                'availableDisplayPercentage' => null,
                'linearAvailablePercentage' => null,
                'availableLabel' => null,
                'availableTitle' => null,
                'showAvailableSegment' => false,
                'isMaximumConfigured' => false,
                'usesCompressedAvailableScale' => false,
                'compressedAvailableLabel' => null,
                'compressionNotice' => null,
            ],
            'storages' => [
                'items' => [],
                'total' => [
                    'bytes' => null,
                    'label' => $notAvailable,
                    'available' => false,
                    'summaryLabel' => $notAvailable,
                ],
            ],
            'mediaBreakdown' => [
                'storages' => [],
            ],
            'mediaBreakdownTotal' => $this->createEmptyValue($notAvailable),
            'largestFalFiles' => [
                'items' => [],
            ],
            'database' => [
                'connections' => [],
                'total' => [
                    'bytes' => null,
                    'label' => $notAvailable,
                    'available' => false,
                    'summaryLabel' => $notAvailable,
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
     * @param array<string, mixed> $overview
     *
     * @return array<string, mixed>
     */
    private function enrichOverviewWithHistory(array $overview): array
    {
        $comparisons = $this->historyService->getComparisons($overview);
        $totalComparisons = $comparisons['total'] ?? [];
        $overview['comparisons'] = $comparisons;
        $overview['total']['comparisons'] = $totalComparisons;
        $overview['total']['comparisonOptions'] = $this->buildComparisonOptions($totalComparisons);
        $overview['total']['hasComparisons'] = [] !== array_filter(
            $totalComparisons,
            static fn (mixed $comparison): bool => is_array($comparison) && (bool)($comparison['available'] ?? false)
        );
        $overview['total']['defaultComparisonPeriod'] = $this->resolveDefaultComparisonPeriod($overview['total']['comparisonOptions']);
        $overview['total']['defaultComparisonLabel'] = $this->resolveDefaultComparisonLabel(
            $overview['total']['comparisonOptions'],
            $overview['total']['defaultComparisonPeriod']
        );

        return $overview;
    }

    /**
     * @param array<string, mixed> $comparisons
     *
     * @return list<array{identifier: string, label: string, available: bool}>
     */
    private function buildComparisonOptions(array $comparisons): array
    {
        $options = [];
        foreach (['day', 'week', 'month'] as $period) {
            $comparison = $comparisons[$period] ?? null;
            $options[] = [
                'identifier' => $period,
                'label' => is_array($comparison) ? (string)($comparison['label'] ?? '') : '',
                'available' => is_array($comparison) && (bool)($comparison['available'] ?? false),
            ];
        }

        return $options;
    }

    /**
     * @param list<array{identifier: string, label: string, available: bool}> $options
     */
    private function resolveDefaultComparisonPeriod(array $options): ?string
    {
        foreach ($options as $option) {
            if ($option['available']) {
                return $option['identifier'];
            }
        }

        return null;
    }

    /**
     * @param list<array{identifier: string, label: string, available: bool}> $options
     */
    private function resolveDefaultComparisonLabel(array $options, ?string $defaultPeriod): string
    {
        if (null === $defaultPeriod) {
            return '';
        }

        foreach ($options as $option) {
            if ($option['identifier'] === $defaultPeriod) {
                return $option['label'];
            }
        }

        return '';
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
     * @return array{identifier: string, label: string, bytes: int, formattedBytes: string, percentage: float, displayPercentage: float, linearPercentage: float, colorClass: string}
     */
    private function createEmptyChartCategory(string $identifier, string $labelKey, string $colorClass, string $notAvailable): array
    {
        return [
            'identifier' => $identifier,
            'label' => $this->translate($labelKey),
            'bytes' => 0,
            'formattedBytes' => $notAvailable,
            'percentage' => 0.0,
            'displayPercentage' => 0.0,
            'linearPercentage' => 0.0,
            'colorClass' => $colorClass,
        ];
    }

    /**
     * @param array<string, mixed> $overview
     *
     * @return array<string, mixed>
     */
    private function normalizeChartViewData(array $overview): array
    {
        $chart = $overview['chart'] ?? null;
        if (!is_array($chart)) {
            return $overview;
        }

        $chart['categories'] = array_map(function (mixed $category): mixed {
            if (!is_array($category)) {
                return $category;
            }

            $percentage = (float)($category['percentage'] ?? 0.0);
            $category['displayPercentage'] = (float)($category['displayPercentage'] ?? $percentage);
            $category['linearPercentage'] = (float)($category['linearPercentage'] ?? $percentage);

            return $category;
        }, is_array($chart['categories'] ?? null) ? $chart['categories'] : []);

        $chart['availableDisplayPercentage'] ??= ($chart['availablePercentage'] ?? null);
        $chart['linearAvailablePercentage'] ??= ($chart['availablePercentage'] ?? null);
        $chart['usesCompressedAvailableScale'] = (bool)($chart['usesCompressedAvailableScale'] ?? false);
        $chart['compressionNotice'] = null;
        $chart['compressedAvailableLabel'] = $this->normalizeCompressedAvailableLabel($chart);
        $chart['availableTitle'] = $this->normalizeAvailableTitle($chart);

        $overview['chart'] = $chart;

        return $overview;
    }

    /**
     * @param array<string, mixed> $chart
     */
    private function normalizeCompressedAvailableLabel(array $chart): ?string
    {
        $availableLabel = $chart['availableLabel'] ?? null;
        if (!is_string($availableLabel) || '' === $availableLabel) {
            return null;
        }

        if (($chart['usesCompressedAvailableScale'] ?? false) !== true) {
            return $chart['compressedAvailableLabel'] ?? $availableLabel;
        }

        return sprintf(
            '%s (%s)',
            $availableLabel,
            $this->translate('module.storageStatistics.compressedAvailableLabel')
        );
    }

    /**
     * @param array<string, mixed> $chart
     */
    private function normalizeAvailableTitle(array $chart): ?string
    {
        $availableLabel = $chart['availableLabel'] ?? null;
        if (!is_string($availableLabel) || '' === $availableLabel) {
            return null;
        }

        if (($chart['usesCompressedAvailableScale'] ?? false) === true) {
            return sprintf(
                $this->translate('module.storageStatistics.availableCompressedTitle'),
                $availableLabel
            );
        }

        return $this->translate('module.storageStatistics.available') . ': ' . $availableLabel;
    }

    private function formatAgeLabel(?int $timestamp): string
    {
        if (null === $timestamp) {
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
        if (null === $durationMs) {
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
        if (null === $snapshotCalculatedAt) {
            return null;
        }

        if (null === $lastNotificationCheck || $lastNotificationCheck['calculatedAt'] !== $snapshotCalculatedAt) {
            return $this->translate('module.storageStatistics.notification.none');
        }

        $warningRecipients = $lastNotificationCheck['warningRecipients'];
        $fullRecipients = $lastNotificationCheck['fullRecipients'];

        if ([] !== $warningRecipients && [] !== $fullRecipients) {
            return sprintf(
                $this->translate('module.storageStatistics.notification.warningAndFull'),
                implode(', ', $warningRecipients),
                implode(', ', $fullRecipients),
            );
        }
        if ([] !== $warningRecipients) {
            return sprintf(
                $this->translate('module.storageStatistics.notification.warning'),
                implode(', ', $warningRecipients),
            );
        }
        if ([] !== $fullRecipients) {
            return sprintf(
                $this->translate('module.storageStatistics.notification.full'),
                implode(', ', $fullRecipients),
            );
        }

        return $this->translate('module.storageStatistics.notification.none');
    }

    private function translate(string $key): string
    {
        return $this->backendLocalizationHelper->translate($key);
    }
}
