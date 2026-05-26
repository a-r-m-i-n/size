<?php

declare(strict_types = 1);

namespace T3\Size\Service;

use T3\Size\Localization\BackendLocalizationHelper;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Registry;

final readonly class StorageStatisticsHistoryService
{
    private const REGISTRY_NAMESPACE = 'size';
    private const REGISTRY_KEY = 'storage_history';
    private const RETENTION_DAYS = 31;
    private const RETENTION_WEEKS = 12;
    private const RETENTION_MONTHS = 12;
    private const LIMIT_REFERENCE_DISPLAY_THRESHOLD = 0.9;
    /**
     * @var list<string>
     */
    private const METRICS = ['total', 'media', 'database', 'code', 'misc'];
    /**
     * @var list<string>
     */
    private const PERIODS = ['day', 'week', 'month'];
    /**
     * @var list<string>
     */
    private const CHART_METRICS = ['media', 'database', 'code', 'misc'];

    public function __construct(
        private Registry $registry,
        private ExtensionConfiguration $extensionConfiguration,
        private MaximumTotalStorageService $maximumTotalStorageService,
        private ByteFormatter $byteFormatter,
        private BackendLocalizationHelper $backendLocalizationHelper,
        private UriBuilder $uriBuilder,
    ) {
    }

    public function isHistoryEnabled(): bool
    {
        try {
            $configuration = $this->extensionConfiguration->get('size');
        } catch (\Throwable) {
            return true;
        }

        if (!is_array($configuration) || !array_key_exists('enableHistory', $configuration)) {
            return true;
        }

        $value = $configuration['enableHistory'];
        if (is_bool($value)) {
            return $value;
        }

        return !in_array(strtolower(trim((string)$value)), ['0', 'false', 'off', 'no'], true);
    }

    /**
     * @param array<string, mixed> $overview
     */
    public function storeOverviewSnapshot(array $overview, int $calculatedAt): void
    {
        if (!$this->isHistoryEnabled()) {
            return;
        }

        $history = $this->getHistory();
        $snapshot = $this->createSnapshotEntry($overview, $calculatedAt);
        $history['days'][$snapshot['date']] = $snapshot;

        $currentDate = $this->createDate($calculatedAt);
        $previousWeekKey = $currentDate->modify('-1 week')->format('o-\\WW');
        $previousMonthKey = $currentDate->modify('first day of last month')->format('Y-m');

        $weeklyEntry = $this->findLatestDailyEntryForWeek($history['days'], $previousWeekKey);
        if (null !== $weeklyEntry) {
            $history['weeks'][$previousWeekKey] = $this->createPeriodEntry($weeklyEntry, 'week', $previousWeekKey);
        }

        $monthlyEntry = $this->findLatestDailyEntryForMonth($history['days'], $previousMonthKey);
        if (null !== $monthlyEntry) {
            $history['months'][$previousMonthKey] = $this->createPeriodEntry($monthlyEntry, 'month', $previousMonthKey);
        }

        $history['days'] = $this->retainNewestEntries($history['days'], self::RETENTION_DAYS);
        $history['weeks'] = $this->retainNewestEntries($history['weeks'], self::RETENTION_WEEKS);
        $history['months'] = $this->retainNewestEntries($history['months'], self::RETENTION_MONTHS);

        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, $history);
    }

    public function resetHistory(): void
    {
        $this->registry->remove(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY);
    }

    /**
     * @param array<string, mixed> $overview
     *
     * @return array<string, array<string, mixed>>
     */
    public function getComparisons(array $overview): array
    {
        if (!$this->isHistoryEnabled()) {
            return $this->createEmptyComparisons();
        }

        $history = $this->getHistory();
        $currentMetrics = $this->extractMetricsFromOverview($overview);
        $latestPreviousDay = $this->getPreviousDailyEntry($history['days']);
        $latestWeek = $this->getLatestPeriodEntry($history['weeks']);
        $latestMonth = $this->getLatestPeriodEntry($history['months']);
        $comparisons = [];

        foreach (self::METRICS as $metric) {
            $comparisons[$metric] = [
                'day' => $this->createComparison(
                    $currentMetrics[$metric],
                    $latestPreviousDay,
                    $metric,
                    'day'
                ),
                'week' => $this->createComparison(
                    $currentMetrics[$metric],
                    $latestWeek,
                    $metric,
                    'week'
                ),
                'month' => $this->createComparison(
                    $currentMetrics[$metric],
                    $latestMonth,
                    $metric,
                    'month'
                ),
            ];
        }

        return $comparisons;
    }

    /**
     * @param array<string, mixed> $overview
     *
     * @return array<string, mixed>
     */
    public function getHistoryModuleData(array $overview, string $selectedPeriod): array
    {
        $period = in_array($selectedPeriod, self::PERIODS, true) ? $selectedPeriod : 'day';
        $history = $this->getHistory();
        $currentMetrics = $this->extractMetricsFromOverview($overview);
        $comparisons = $this->getComparisons($overview);

        $periodData = [
            'day' => $this->createPeriodViewData('day', $history['days']),
            'week' => $this->createPeriodViewData('week', $history['weeks']),
            'month' => $this->createPeriodViewData('month', $history['months']),
        ];

        return [
            'historyEnabled' => $this->isHistoryEnabled(),
            'selectedPeriod' => $period,
            'periodOptions' => $this->buildPeriodOptions($period),
            'currentTotal' => [
                'identifier' => 'total',
                'label' => $this->translateMetric('total'),
                'bytes' => $currentMetrics['total'],
                'formattedBytes' => $this->byteFormatter->format($currentMetrics['total']),
                'maximumBytes' => (int)($overview['chart']['maximumBytes'] ?? 0),
                'maximumLabel' => null !== ($overview['chart']['maximumBytes'] ?? null)
                    ? $this->byteFormatter->format((int)$overview['chart']['maximumBytes'])
                    : null,
                'percentageLabel' => (bool)($overview['chart']['isMaximumConfigured'] ?? false)
                    ? number_format((float)($overview['chart']['totalPercentage'] ?? 0.0), 1, '.', '') . '%'
                    : null,
            ],
            'selectedMetricComparisons' => $this->filterHistoryModuleComparisons($comparisons['total'] ?? []),
            'dayHistory' => $periodData['day'],
            'weekHistory' => $periodData['week'],
            'monthHistory' => $periodData['month'],
            'selectedPeriodData' => $periodData[$period],
        ];
    }

    /**
     * @return array{days: array<string, array<string, int|string>>, weeks: array<string, array<string, int|string>>, months: array<string, array<string, int|string>>}
     */
    private function getHistory(): array
    {
        $history = $this->registry->get(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY);
        if (!is_array($history)) {
            return [
                'days' => [],
                'weeks' => [],
                'months' => [],
            ];
        }

        return [
            'days' => $this->sanitizePeriodEntries($history['days'] ?? [], 'date'),
            'weeks' => $this->sanitizePeriodEntries($history['weeks'] ?? [], 'week'),
            'months' => $this->sanitizePeriodEntries($history['months'] ?? [], 'month'),
        ];
    }

    /**
     * @return array<string, array<string, int|string>>
     */
    private function sanitizePeriodEntries(mixed $entries, string $periodKey): array
    {
        if (!is_array($entries)) {
            return [];
        }

        $sanitized = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry[$periodKey], $entry['calculatedAt'])) {
                continue;
            }
            $key = (string)$entry[$periodKey];
            $sanitized[$key] = [
                $periodKey => $key,
                'calculatedAt' => (int)$entry['calculatedAt'],
                'media' => (int)($entry['media'] ?? 0),
                'database' => (int)($entry['database'] ?? 0),
                'code' => (int)($entry['code'] ?? 0),
                'misc' => (int)($entry['misc'] ?? 0),
                'total' => (int)($entry['total'] ?? 0),
            ];
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $overview
     *
     * @return array{date: string, calculatedAt: int, media: int, database: int, code: int, misc: int, total: int}
     */
    private function createSnapshotEntry(array $overview, int $calculatedAt): array
    {
        $metrics = $this->extractMetricsFromOverview($overview);

        return [
            'date' => $this->createDate($calculatedAt)->format('Y-m-d'),
            'calculatedAt' => $calculatedAt,
            'media' => $metrics['media'],
            'database' => $metrics['database'],
            'code' => $metrics['code'],
            'misc' => $metrics['misc'],
            'total' => $metrics['total'],
        ];
    }

    /**
     * @param array{date: string, calculatedAt: int, media: int, database: int, code: int, misc: int, total: int} $entry
     *
     * @return array<string, int|string>
     */
    private function createPeriodEntry(array $entry, string $periodType, string $periodKey): array
    {
        return [
            $periodType => $periodKey,
            'calculatedAt' => $entry['calculatedAt'],
            'media' => $entry['media'],
            'database' => $entry['database'],
            'code' => $entry['code'],
            'misc' => $entry['misc'],
            'total' => $entry['total'],
        ];
    }

    /**
     * @param array<string, array<string, int|string>> $entries
     *
     * @return array{date: string, calculatedAt: int, media: int, database: int, code: int, misc: int, total: int}|null
     */
    private function findLatestDailyEntryForWeek(array $entries, string $weekKey): ?array
    {
        $matches = array_filter(
            $entries,
            fn (array $entry): bool => $this->createDate((int)$entry['calculatedAt'])->format('o-\\WW') === $weekKey
        );

        if ([] === $matches) {
            return null;
        }

        usort($matches, fn (array $first, array $second): int => ((int)$second['calculatedAt']) <=> ((int)$first['calculatedAt']));
        $entry = $matches[array_key_first($matches)];

        return [
            'date' => (string)$entry['date'],
            'calculatedAt' => (int)$entry['calculatedAt'],
            'media' => (int)$entry['media'],
            'database' => (int)$entry['database'],
            'code' => (int)$entry['code'],
            'misc' => (int)$entry['misc'],
            'total' => (int)$entry['total'],
        ];
    }

    /**
     * @param array<string, array<string, int|string>> $entries
     *
     * @return array{date: string, calculatedAt: int, media: int, database: int, code: int, misc: int, total: int}|null
     */
    private function findLatestDailyEntryForMonth(array $entries, string $monthKey): ?array
    {
        $matches = array_filter(
            $entries,
            fn (array $entry): bool => str_starts_with((string)$entry['date'], $monthKey . '-')
        );

        if ([] === $matches) {
            return null;
        }

        usort($matches, fn (array $first, array $second): int => ((int)$second['calculatedAt']) <=> ((int)$first['calculatedAt']));
        $entry = $matches[array_key_first($matches)];

        return [
            'date' => (string)$entry['date'],
            'calculatedAt' => (int)$entry['calculatedAt'],
            'media' => (int)$entry['media'],
            'database' => (int)$entry['database'],
            'code' => (int)$entry['code'],
            'misc' => (int)$entry['misc'],
            'total' => (int)$entry['total'],
        ];
    }

    /**
     * @template T of array<string, int|string>
     *
     * @param array<string, T> $entries
     *
     * @return array<string, T>
     */
    private function retainNewestEntries(array $entries, int $limit): array
    {
        krsort($entries);

        return array_slice($entries, 0, $limit, true);
    }

    /**
     * @param array<string, mixed> $overview
     *
     * @return array{total: int, media: int, database: int, code: int, misc: int}
     */
    private function extractMetricsFromOverview(array $overview): array
    {
        $media = (int)($overview['storages']['total']['bytes'] ?? 0);
        $database = (int)($overview['database']['total']['bytes'] ?? 0);
        $code = (int)($overview['code']['total']['bytes'] ?? 0);
        $misc = (int)($overview['misc']['total']['bytes'] ?? 0);
        $total = (int)($overview['total']['bytes'] ?? ($media + $database + $code + $misc));

        return [
            'total' => $total,
            'media' => $media,
            'database' => $database,
            'code' => $code,
            'misc' => $misc,
        ];
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function createEmptyComparisons(): array
    {
        $comparisons = [];
        foreach (self::METRICS as $metric) {
            $comparisons[$metric] = [
                'day' => $this->createUnavailableComparison('day', $metric),
                'week' => $this->createUnavailableComparison('week', $metric),
                'month' => $this->createUnavailableComparison('month', $metric),
            ];
        }

        return $comparisons;
    }

    /**
     * @param array<string, array<string, mixed>> $comparisons
     *
     * @return array<string, array<string, mixed>>
     */
    private function filterHistoryModuleComparisons(array $comparisons): array
    {
        $orderedComparisons = [];
        foreach (['day', 'week', 'month'] as $period) {
            if (isset($comparisons[$period])) {
                $orderedComparisons[$period] = $comparisons[$period];
            }
        }

        return $orderedComparisons;
    }

    /**
     * @param array<string, array<string, int|string>> $entries
     *
     * @return array<string, int|string>|null
     */
    private function getLatestPeriodEntry(array $entries): ?array
    {
        if ([] === $entries) {
            return null;
        }

        krsort($entries);

        return $entries[array_key_first($entries)] ?? null;
    }

    /**
     * @param array<string, array<string, int|string>> $entries
     *
     * @return array<string, int|string>|null
     */
    private function getPreviousDailyEntry(array $entries): ?array
    {
        if (count($entries) < 2) {
            return null;
        }

        krsort($entries);
        $values = array_values($entries);

        return $values[1] ?? null;
    }

    /**
     * @param array<string, int|string>|null $reference
     *
     * @return array<string, mixed>
     */
    private function createComparison(int $currentValue, ?array $reference, string $metric, string $period): array
    {
        if (null === $reference) {
            return $this->createUnavailableComparison($period, $metric);
        }

        $referenceValue = (int)($reference[$metric] ?? 0);
        $delta = $currentValue - $referenceValue;
        $percentage = 0 === $referenceValue ? null : ($delta / $referenceValue) * 100;
        $direction = $delta < 0 ? 'down' : ($delta > 0 ? 'up' : 'same');

        return [
            'available' => true,
            'period' => $period,
            'metric' => $metric,
            'label' => $this->translatePeriod($period),
            'deltaBytes' => $delta,
            'deltaLabel' => $this->formatSignedBytes($delta),
            'percentage' => $percentage,
            'percentageLabel' => null === $percentage ? null : $this->formatPercentage($percentage),
            'direction' => $direction,
            'url' => (string)$this->uriBuilder->buildUriFromRoute('size_storage_statistics_history', [
                'metric' => $metric,
                'period' => $period,
            ]),
            'referenceValue' => $referenceValue,
            'referenceLabel' => $this->byteFormatter->format($referenceValue),
            'referenceDateLabel' => $this->formatReferenceDateLabel($reference, $period),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createUnavailableComparison(string $period, string $metric): array
    {
        return [
            'available' => false,
            'period' => $period,
            'metric' => $metric,
            'label' => $this->translatePeriod($period),
            'deltaBytes' => null,
            'deltaLabel' => null,
            'percentage' => null,
            'percentageLabel' => null,
            'direction' => 'none',
            'url' => (string)$this->uriBuilder->buildUriFromRoute('size_storage_statistics_history', [
                'metric' => $metric,
                'period' => $period,
            ]),
            'referenceValue' => null,
            'referenceLabel' => null,
            'referenceDateLabel' => null,
        ];
    }

    /**
     * @param array<string, int|string> $reference
     */
    private function formatReferenceDateLabel(array $reference, string $period): string
    {
        return match ($period) {
            'week' => (string)($reference['week'] ?? ''),
            'month' => (string)($reference['month'] ?? ''),
            default => (string)($reference['date'] ?? ''),
        };
    }

    /**
     * @param array<string, array<string, int|string>> $entries
     *
     * @return array<string, mixed>
     */
    private function createPeriodViewData(string $period, array $entries): array
    {
        ksort($entries);
        $items = [];
        $previousEntry = null;
        foreach ($entries as $key => $entry) {
            $items[] = $this->createHistoryItem($period, $key, $entry, $previousEntry);
            $previousEntry = $entry;
        }
        $chartData = $this->buildChartData($items);

        return [
            'identifier' => $period,
            'label' => $this->translateHistoryRange($period),
            'metricLabel' => $this->translateMetric('total'),
            'description' => $this->buildHistoryDescription($chartData),
            'items' => $items,
            'hasItems' => [] !== $items,
            'chart' => $chartData,
            'chartJson' => (string)json_encode($chartData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * @param array<string, int|string>      $entry
     * @param array<string, int|string>|null $previousEntry
     *
     * @return array<string, mixed>
     */
    private function createHistoryItem(string $period, string $key, array $entry, ?array $previousEntry): array
    {
        return [
            'identifier' => $key,
            'periodLabel' => $this->formatPeriodLabel($period, $key),
            'axisLabel' => $this->formatChartAxisLabel($key),
            'recordedAtLabel' => date('Y-m-d H:i:s', (int)$entry['calculatedAt']),
            'media' => (int)($entry['media'] ?? 0),
            'mediaLabel' => $this->byteFormatter->format((int)($entry['media'] ?? 0)),
            'mediaChange' => $this->createRowChangeData((int)($entry['media'] ?? 0), $previousEntry, 'media'),
            'database' => (int)($entry['database'] ?? 0),
            'databaseLabel' => $this->byteFormatter->format((int)($entry['database'] ?? 0)),
            'databaseChange' => $this->createRowChangeData((int)($entry['database'] ?? 0), $previousEntry, 'database'),
            'code' => (int)($entry['code'] ?? 0),
            'codeLabel' => $this->byteFormatter->format((int)($entry['code'] ?? 0)),
            'codeChange' => $this->createRowChangeData((int)($entry['code'] ?? 0), $previousEntry, 'code'),
            'misc' => (int)($entry['misc'] ?? 0),
            'miscLabel' => $this->byteFormatter->format((int)($entry['misc'] ?? 0)),
            'miscChange' => $this->createRowChangeData((int)($entry['misc'] ?? 0), $previousEntry, 'misc'),
            'total' => (int)($entry['total'] ?? 0),
            'totalLabel' => $this->byteFormatter->format((int)($entry['total'] ?? 0)),
            'totalChange' => $this->createRowChangeData((int)($entry['total'] ?? 0), $previousEntry, 'total'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function buildChartData(array $items): array
    {
        $formattedValues = [];
        foreach (self::CHART_METRICS as $metric) {
            $formattedValues[$metric] = array_map(
                static fn (array $item): string => (string)$item[$metric . 'Label'],
                $items
            );
        }

        $limitBytes = $this->maximumTotalStorageService->getMaximumTotalStorageBytes();
        $totals = array_map(static fn (array $item): int => (int)$item['total'], $items);
        $maxTotalBytes = [] !== $totals ? max($totals) : 0;
        $showsLimitReference = null !== $limitBytes
            && $limitBytes > 0
            && ($maxTotalBytes / $limitBytes) >= self::LIMIT_REFERENCE_DISPLAY_THRESHOLD;

        return [
            'labels' => array_map(static fn (array $item): string => (string)$item['axisLabel'], $items),
            'totals' => $totals,
            'formattedTotals' => array_map(static fn (array $item): string => (string)$item['totalLabel'], $items),
            'formattedValues' => $formattedValues,
            'datasets' => [
                $this->createChartDataset('media', 'section.fileadmin', 'var(--size-storage-media)', $items),
                $this->createChartDataset('database', 'section.database', 'var(--size-storage-database)', $items),
                $this->createChartDataset('code', 'section.code', 'var(--size-storage-code)', $items),
                $this->createChartDataset('misc', 'section.misc', 'var(--size-storage-misc)', $items),
            ],
            'usesCompressedLimitReference' => !$showsLimitReference,
            'compressionNotice' => null,
            'limit' => [
                'enabled' => $showsLimitReference,
                'bytes' => $limitBytes,
                'label' => null !== $limitBytes ? $this->byteFormatter->format($limitBytes) : null,
                'datasetLabel' => $this->backendLocalizationHelper->translate('history.chart.limitDataset'),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPeriodOptions(string $selectedPeriod): array
    {
        $items = [];
        foreach (self::PERIODS as $period) {
            $items[] = [
                'identifier' => $period,
                'label' => $this->translatePeriodOption($period),
                'isActive' => $period === $selectedPeriod,
                'url' => (string)$this->uriBuilder->buildUriFromRoute('size_storage_statistics_history', [
                    'period' => $period,
                ]),
            ];
        }

        return $items;
    }

    private function translateMetric(string $metric): string
    {
        return match ($metric) {
            'media' => $this->backendLocalizationHelper->translate('section.fileadmin'),
            'database' => $this->backendLocalizationHelper->translate('section.database'),
            'code' => $this->backendLocalizationHelper->translate('section.code'),
            'misc' => $this->backendLocalizationHelper->translate('section.misc'),
            default => $this->backendLocalizationHelper->translate('section.total'),
        };
    }

    private function translatePeriod(string $period): string
    {
        return match ($period) {
            'day' => $this->backendLocalizationHelper->translate('history.compareDay'),
            'week' => $this->backendLocalizationHelper->translate('history.compareWeek'),
            'month' => $this->backendLocalizationHelper->translate('history.compareMonth'),
            default => $this->backendLocalizationHelper->translate('history.range.day'),
        };
    }

    private function translateHistoryRange(string $period): string
    {
        return match ($period) {
            'week' => $this->backendLocalizationHelper->translate('history.range.week'),
            'month' => $this->backendLocalizationHelper->translate('history.range.month'),
            default => $this->backendLocalizationHelper->translate('history.range.day'),
        };
    }

    private function translatePeriodOption(string $period): string
    {
        return match ($period) {
            'week' => $this->backendLocalizationHelper->translate('history.period.week'),
            'month' => $this->backendLocalizationHelper->translate('history.period.month'),
            default => $this->backendLocalizationHelper->translate('history.period.day'),
        };
    }

    /**
     * @param array<string, mixed> $chartData
     */
    private function buildHistoryDescription(array $chartData): string
    {
        $limit = is_array($chartData['limit'] ?? null) ? $chartData['limit'] : [];
        if (($limit['enabled'] ?? false) !== true) {
            return $this->backendLocalizationHelper->translate('history.chart.description');
        }

        return sprintf(
            $this->backendLocalizationHelper->translate('history.chart.descriptionWithLimit'),
            (string)($limit['label'] ?? '')
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array{identifier: string, label: string, data: list<int>, backgroundColor: string}
     */
    private function createChartDataset(string $metric, string $labelKey, string $backgroundColor, array $items): array
    {
        return [
            'identifier' => $metric,
            'label' => $this->backendLocalizationHelper->translate($labelKey),
            'data' => array_map(static fn (array $item): int => (int)$item[$metric], $items),
            'backgroundColor' => $backgroundColor,
        ];
    }

    /**
     * @param array<string, int|string>|null $previousEntry
     *
     * @return array{available: bool, label: string|null, direction: string}
     */
    private function createRowChangeData(int $currentValue, ?array $previousEntry, string $metric): array
    {
        if (null === $previousEntry) {
            return [
                'available' => false,
                'label' => null,
                'direction' => 'none',
            ];
        }

        $previousValue = (int)($previousEntry[$metric] ?? 0);
        $delta = $currentValue - $previousValue;

        if (0 === $delta) {
            return [
                'available' => true,
                'label' => '(-)',
                'direction' => 'same',
            ];
        }

        if (0 === $previousValue) {
            return [
                'available' => true,
                'label' => sprintf('(%sn/a)', $delta > 0 ? '+' : '-'),
                'direction' => $delta > 0 ? 'up' : 'down',
            ];
        }

        $percentage = ($delta / $previousValue) * 100;

        return [
            'available' => true,
            'label' => sprintf('(%s)', $this->formatPercentage($percentage)),
            'direction' => $delta > 0 ? 'up' : 'down',
        ];
    }

    private function formatSignedBytes(int $value): string
    {
        if (0 === $value) {
            return '0 B';
        }

        return sprintf(
            '%s%s',
            $value > 0 ? '+' : '-',
            $this->byteFormatter->format(abs($value))
        );
    }

    private function formatPercentage(float $value): string
    {
        $prefix = $value > 0 ? '+' : '';

        return $prefix . number_format($value, 1, '.', '') . '%';
    }

    private function formatPeriodLabel(string $period, string $key): string
    {
        return match ($period) {
            'week' => $key,
            'month' => $key,
            default => $key,
        };
    }

    private function formatChartAxisLabel(string $key): string
    {
        if (1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) {
            return substr($key, 5);
        }

        return $key;
    }

    private function createDate(int $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
}
