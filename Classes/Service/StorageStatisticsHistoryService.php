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
    /**
     * @var list<string>
     */
    private const METRICS = ['total', 'media', 'database', 'code', 'misc'];
    /**
     * @var list<string>
     */
    private const PERIODS = ['day', 'week', 'month'];

    public function __construct(
        private Registry $registry,
        private ExtensionConfiguration $extensionConfiguration,
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
    public function getHistoryModuleData(array $overview, string $selectedMetric, string $selectedPeriod): array
    {
        $metric = in_array($selectedMetric, self::METRICS, true) ? $selectedMetric : 'total';
        $period = in_array($selectedPeriod, self::PERIODS, true) ? $selectedPeriod : 'day';
        $history = $this->getHistory();
        $currentMetrics = $this->extractMetricsFromOverview($overview);
        $comparisons = $this->getComparisons($overview);

        $periodData = [
            'day' => $this->createPeriodViewData('day', $history['days'], $metric),
            'week' => $this->createPeriodViewData('week', $history['weeks'], $metric),
            'month' => $this->createPeriodViewData('month', $history['months'], $metric),
        ];

        return [
            'historyEnabled' => $this->isHistoryEnabled(),
            'selectedMetric' => $metric,
            'selectedPeriod' => $period,
            'metricOptions' => $this->buildMetricOptions($metric, $period),
            'periodOptions' => $this->buildPeriodOptions($metric, $period),
            'currentMetric' => [
                'identifier' => $metric,
                'label' => $this->translateMetric($metric),
                'bytes' => $currentMetrics[$metric],
                'formattedBytes' => $this->byteFormatter->format($currentMetrics[$metric]),
            ],
            'selectedMetricComparisons' => $this->filterHistoryModuleComparisons($comparisons[$metric] ?? []),
            'allMetricComparisons' => $comparisons,
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
        return array_intersect_key($comparisons, array_flip(['week', 'month']));
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
    private function createPeriodViewData(string $period, array $entries, string $metric): array
    {
        ksort($entries);
        $items = [];
        foreach ($entries as $key => $entry) {
            $items[] = $this->createHistoryItem($period, $key, $entry, $metric);
        }

        return [
            'identifier' => $period,
            'label' => $this->translateHistoryRange($period),
            'metricLabel' => $this->translateMetric($metric),
            'items' => $items,
            'hasItems' => [] !== $items,
            'chart' => $this->buildChartData($items, $metric),
        ];
    }

    /**
     * @param array<string, int|string> $entry
     *
     * @return array<string, mixed>
     */
    private function createHistoryItem(string $period, string $key, array $entry, string $metric): array
    {
        $bytes = (int)($entry[$metric] ?? 0);

        return [
            'identifier' => $key,
            'periodLabel' => $this->formatPeriodLabel($period, $key),
            'recordedAtLabel' => date('Y-m-d H:i:s', (int)$entry['calculatedAt']),
            'media' => (int)($entry['media'] ?? 0),
            'mediaLabel' => $this->byteFormatter->format((int)($entry['media'] ?? 0)),
            'database' => (int)($entry['database'] ?? 0),
            'databaseLabel' => $this->byteFormatter->format((int)($entry['database'] ?? 0)),
            'code' => (int)($entry['code'] ?? 0),
            'codeLabel' => $this->byteFormatter->format((int)($entry['code'] ?? 0)),
            'misc' => (int)($entry['misc'] ?? 0),
            'miscLabel' => $this->byteFormatter->format((int)($entry['misc'] ?? 0)),
            'total' => (int)($entry['total'] ?? 0),
            'totalLabel' => $this->byteFormatter->format((int)($entry['total'] ?? 0)),
            'bytes' => $bytes,
            'formattedBytes' => $this->byteFormatter->format($bytes),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function buildChartData(array $items, string $metric): array
    {
        if ([] === $items) {
            return [
                'viewBox' => '0 0 100 100',
                'polylinePoints' => '',
                'areaPoints' => '',
                'gridLines' => [],
                'labels' => [],
                'valueLabels' => [],
                'metricLabel' => $this->translateMetric($metric),
            ];
        }

        $width = 960;
        $height = 280;
        $paddingLeft = 24;
        $paddingRight = 24;
        $paddingTop = 20;
        $paddingBottom = 40;
        $plotWidth = $width - $paddingLeft - $paddingRight;
        $plotHeight = $height - $paddingTop - $paddingBottom;
        $maxValue = max(array_map(static fn (array $item): int => (int)$item['bytes'], $items));
        $maxValue = max($maxValue, 1);

        $polylinePoints = [];
        $labelPoints = [];
        $valueLabels = [];
        $count = count($items);
        foreach ($items as $index => $item) {
            $x = 1 === $count ? $paddingLeft + (int)round($plotWidth / 2) : $paddingLeft + (int)round(($plotWidth / max(1, $count - 1)) * $index);
            $y = $paddingTop + (int)round($plotHeight - (((int)$item['bytes'] / $maxValue) * $plotHeight));
            $polylinePoints[] = $x . ',' . $y;
            $labelPoints[] = [
                'x' => $x,
                'y' => $height - 12,
                'label' => $this->formatChartAxisLabel((string)$item['identifier']),
            ];
            $valueLabels[] = [
                'x' => $x,
                'y' => max(16, $y - 10),
                'label' => (string)$item['formattedBytes'],
            ];
        }

        $gridLines = [];
        foreach ([0, 0.25, 0.5, 0.75, 1.0] as $ratio) {
            $value = (int)round($maxValue * (1 - $ratio));
            $gridY = $paddingTop + (int)round($plotHeight * $ratio);
            $gridLines[] = [
                'y' => $gridY,
                'labelY' => max(12, $gridY - 6),
                'label' => $this->byteFormatter->format($value),
            ];
        }

        return [
            'viewBox' => '0 0 ' . $width . ' ' . $height,
            'polylinePoints' => implode(' ', $polylinePoints),
            'areaPoints' => $paddingLeft . ',' . ($height - $paddingBottom) . ' ' . implode(' ', $polylinePoints) . ' ' . ($paddingLeft + $plotWidth) . ',' . ($height - $paddingBottom),
            'gridLines' => $gridLines,
            'labels' => $labelPoints,
            'valueLabels' => $valueLabels,
            'metricLabel' => $this->translateMetric($metric),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildMetricOptions(string $selectedMetric, string $selectedPeriod): array
    {
        $items = [];
        foreach (self::METRICS as $metric) {
            $items[] = [
                'identifier' => $metric,
                'label' => $this->translateMetric($metric),
                'isActive' => $metric === $selectedMetric,
                'url' => (string)$this->uriBuilder->buildUriFromRoute('size_storage_statistics_history', [
                    'metric' => $metric,
                    'period' => $selectedPeriod,
                ]),
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPeriodOptions(string $selectedMetric, string $selectedPeriod): array
    {
        $items = [];
        foreach (self::PERIODS as $period) {
            $items[] = [
                'identifier' => $period,
                'label' => $this->translateHistoryRange($period),
                'isActive' => $period === $selectedPeriod,
                'url' => (string)$this->uriBuilder->buildUriFromRoute('size_storage_statistics_history', [
                    'metric' => $selectedMetric,
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
