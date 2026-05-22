<?php

declare(strict_types=1);

namespace T3\Size\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection as Typo3Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\FileType;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use Symfony\Component\Process\Process;

final class SizeOverviewCalculator
{
    private const NOT_AVAILABLE = 'notAvailable';
    private const MEDIA_IMAGES = 'images';
    private const MEDIA_VIDEOS = 'videos';
    private const MEDIA_DOCUMENTS = 'documents';
    private const MEDIA_AUDIO = 'audio';
    private const MEDIA_ARCHIVES = 'archives';
    private const MEDIA_PROCESSED_IMAGES = 'processedImages';
    private const MEDIA_OTHER = 'other';

    /**
     * @var array{
     *   code: array<string, array{bytes: int|null, label: string}>,
     *   misc: array{bytes: int, label: string},
     *   chart: array{
     *     categories: list<array{
     *       identifier: string,
     *       label: string,
     *       bytes: int,
     *       formattedBytes: string,
     *       percentage: float,
     *       colorClass: string
     *     }>,
     *     maximumBytes: int|null,
     *     referenceBytes: int,
     *     totalPercentage: float,
     *     availableBytes: int|null,
     *     availablePercentage: float|null,
     *     availableLabel: string|null,
     *     showAvailableSegment: bool,
     *     isMaximumConfigured: bool
     *   },
     *   storages: array{
     *     items: list<array{name: string, bytes: int|null, label: string}>,
     *     total: array{bytes: int|null, label: string, available: bool}
     *   },
     *   mediaBreakdown: array{
     *     storages: list<array{
     *       name: string,
     *       total: array{bytes: int, label: string},
     *       categories: list<array{
     *         identifier: string,
     *         iconIdentifier: string,
     *         label: string,
     *         bytes: int,
     *         formattedBytes: string,
     *         fileCount: int
     *       }>
     *     }>
     *   },
     *   mediaBreakdownTotal: array{bytes: int, label: string},
     *   database: array{
     *     connections: list<array{
     *       name: string,
     *       bytes: int|null,
     *       label: string,
     *       available: bool,
     *       tables: list<array{
     *         name: string,
     *         title: string|null,
     *         iconIdentifier: string,
     *         usesFallbackIcon: bool,
     *         rowCount: int|null,
     *         bytes: int|null,
     *         formattedBytes: string,
     *         available: bool
     *       }>
     *     }>,
     *     total: array{bytes: int|null, label: string, available: bool}
     *   },
     *   total: array{bytes: int, label: string, displayLabel: string, highlightClass: string, badgeClass: string}
     * }|null
     */
    private ?array $overviewCache = null;

    /**
     * @var array<string, array{bytes: int, fileCount: int}>
     */
    private array $filesystemMetricsCache = [];

    /**
     * @var array<int, array{bytes: int|null, fileCount: int|null, label: string, paths: list<string>}>
     */
    private array $storageMeasurementCache = [];

    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly ConnectionPool $connectionPool,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * @return array{
     *   code: array<string, array{bytes: int|null, label: string}>,
     *   misc: array{bytes: int, label: string},
     *   chart: array{
     *     categories: list<array{
     *       identifier: string,
     *       label: string,
     *       bytes: int,
     *       formattedBytes: string,
     *       percentage: float,
     *       colorClass: string
     *     }>,
     *     maximumBytes: int|null,
     *     referenceBytes: int,
     *     totalPercentage: float,
     *     availableBytes: int|null,
     *     availablePercentage: float|null,
     *     availableLabel: string|null,
     *     showAvailableSegment: bool,
     *     isMaximumConfigured: bool
     *   },
     *   storages: array{
     *     items: list<array{name: string, bytes: int|null, label: string}>,
     *     total: array{bytes: int|null, label: string, available: bool}
     *   },
     *   mediaBreakdown: array{
     *     storages: list<array{
     *       name: string,
     *       total: array{bytes: int, label: string},
     *       categories: list<array{
     *         identifier: string,
     *         iconIdentifier: string,
     *         label: string,
     *         bytes: int,
     *         formattedBytes: string,
     *         fileCount: int
     *       }>
     *     }>
     *   },
     *   mediaBreakdownTotal: array{bytes: int, label: string},
     *   database: array{
     *     connections: list<array{
     *       name: string,
     *       bytes: int|null,
     *       label: string,
     *       available: bool,
     *       tables: list<array{
     *         name: string,
     *         title: string|null,
     *         iconIdentifier: string,
     *         usesFallbackIcon: bool,
     *         rowCount: int|null,
     *         bytes: int|null,
     *         formattedBytes: string,
     *         available: bool
     *       }>
     *     }>,
     *     total: array{bytes: int|null, label: string, available: bool}
     *   },
     *   total: array{bytes: int, label: string, displayLabel: string, highlightClass: string, badgeClass: string}
     * }
     */
    public function getOverview(): array
    {
        if ($this->overviewCache !== null) {
            return $this->overviewCache;
        }

        $code = $this->getCodeOverview();
        $misc = $this->getMiscOverview();
        $storages = $this->getStorageOverview();
        $mediaBreakdown = $this->getMediaBreakdown();
        $mediaBreakdownTotal = $this->getMediaBreakdownTotal($mediaBreakdown);
        $database = $this->getDatabaseOverview();
        $totalBytes = (
            ($code['total']['bytes'] ?? 0)
            + ($misc['bytes'] ?? 0)
            + ($storages['total']['bytes'] ?? 0)
            + ($database['total']['bytes'] ?? 0)
        );
        $total = $this->createTotalValue($totalBytes);
        $chart = $this->createChartData($storages, $database, $code, $misc, $totalBytes);

        $this->overviewCache = [
            'code' => $code,
            'misc' => $misc,
            'chart' => $chart,
            'storages' => $storages,
            'mediaBreakdown' => $mediaBreakdown,
            'mediaBreakdownTotal' => $mediaBreakdownTotal,
            'database' => $database,
            'total' => $total,
        ];

        return $this->overviewCache;
    }

    /**
     * @return array<string, array{bytes: int|null, label: string}>
     */
    private function getCodeOverview(): array
    {
        $groups = [
            'vendor' => 0,
            'extensions' => 0,
            'dependencies' => 0,
        ];

        foreach ($this->getComposerInstallPaths() as $package) {
            $groups[$package['group']] += $this->getPathSize($package['path']);
        }

        $total = $groups['vendor'] + $groups['extensions'] + $groups['dependencies'];

        return [
            'vendor' => $this->createByteValue($groups['vendor']),
            'extensions' => $this->createByteValue($groups['extensions']),
            'dependencies' => $this->createByteValue($groups['dependencies']),
            'total' => $this->createByteValue($total),
        ];
    }

    /**
     * @return array{bytes: int, label: string}
     */
    private function getMiscOverview(): array
    {
        $projectPath = Environment::getProjectPath();
        $publicPath = Environment::getPublicPath();
        $size = 0;

        foreach (new \FilesystemIterator($projectPath, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isFile() && !$item->isLink()) {
                $size += $item->getSize();
            }
        }

        $size += $this->getPathSize($projectPath . '/var');
        $size += $this->getPathSize($projectPath . '/config');
        $size += $this->getPathSize($publicPath . '/typo3temp');

        return $this->createByteValue($size);
    }

    /**
     * @return array{
     *   items: list<array{name: string, bytes: int|null, label: string}>,
     *   total: array{bytes: int|null, label: string, available: bool}
     * }
     */
    private function getStorageOverview(): array
    {
        $storages = [];
        $countedTotalPaths = [];
        $hasAvailableStorage = false;

        foreach ($this->storageRepository->findAll() as $storage) {
            $storageMeasurement = $this->getStorageMeasurement($storage);
            $storageData = [
                'name' => $storage->getName(),
                'bytes' => $storageMeasurement['bytes'],
                'label' => $storageMeasurement['label'],
            ];
            $storages[] = $storageData;

            if ($storageMeasurement['bytes'] !== null) {
                foreach ($this->getNonOverlappingPaths($storageMeasurement['paths']) as $path) {
                    $countedTotalPaths[$path] = true;
                }
                $hasAvailableStorage = true;
            }
        }

        $totalBytes = 0;
        foreach ($this->getNonOverlappingPaths(array_keys($countedTotalPaths)) as $countedPath) {
            $totalBytes += $this->getFilesystemStorageSize($countedPath);
        }

        return [
            'items' => $storages,
            'total' => $hasAvailableStorage
                ? [...$this->createByteValue($totalBytes), 'available' => true]
                : [...$this->createUnavailableValue(), 'available' => false],
        ];
    }

    /**
     * @return array{bytes: int|null, fileCount: int|null, label: string, paths: list<string>}
     */
    private function getStorageMeasurement(ResourceStorage $storage): array
    {
        if (isset($this->storageMeasurementCache[$storage->getUid()])) {
            return $this->storageMeasurementCache[$storage->getUid()];
        }

        try {
            if (!$storage->isOnline()) {
                return $this->storageMeasurementCache[$storage->getUid()] = [...$this->createUnavailableValue(), 'fileCount' => null, 'paths' => []];
            }

            $paths = $this->getStorageMeasuredPaths($storage);
            if ($paths === []) {
                return $this->storageMeasurementCache[$storage->getUid()] = [...$this->createUnavailableValue(), 'fileCount' => null, 'paths' => []];
            }

            $size = 0;
            $fileCount = 0;
            foreach ($paths as $path) {
                $metrics = $this->getFilesystemStorageMetrics($path);
                $size += $metrics['bytes'];
                $fileCount += $metrics['fileCount'];
            }

            return $this->storageMeasurementCache[$storage->getUid()] = [...$this->createByteValue($size), 'fileCount' => $fileCount, 'paths' => $paths];
        } catch (\Throwable) {
            return $this->storageMeasurementCache[$storage->getUid()] = [...$this->createUnavailableValue(), 'fileCount' => null, 'paths' => []];
        }
    }

    private function resolveLocalStorageBasePath(ResourceStorage $storage): ?string
    {
        if ($storage->getDriverType() !== 'Local') {
            return null;
        }

        $configuration = $storage->getConfiguration();
        $basePath = (string)($configuration['basePath'] ?? '');
        $pathType = (string)($configuration['pathType'] ?? 'absolute');

        if ($basePath === '') {
            return null;
        }

        $absolutePath = $pathType === 'relative'
            ? Environment::getPublicPath() . '/' . ltrim($basePath, '/')
            : $basePath;

        $realPath = realpath($absolutePath);

        return $realPath !== false && is_dir($realPath) ? $realPath : null;
    }

    /**
     * @return list<string>
     */
    private function getStorageMeasuredPaths(ResourceStorage $storage): array
    {
        $basePath = $this->resolveLocalStorageBasePath($storage);
        if ($basePath === null) {
            return [];
        }

        return [$basePath];
    }

    private function getFilesystemStorageSize(string $basePath): int
    {
        return $this->getPathSize($basePath);
    }

    /**
     * @return array{bytes: int, fileCount: int}
     */
    private function getFilesystemStorageMetrics(string $basePath): array
    {
        $normalizedBasePath = $this->normalizePath($basePath) ?? rtrim($basePath, '/');
        if (isset($this->filesystemMetricsCache[$normalizedBasePath])) {
            return $this->filesystemMetricsCache[$normalizedBasePath];
        }

        $size = $this->getPathSize($basePath);
        $fileCount = 0;
        if (!is_dir($basePath) || !is_readable($basePath)) {
            return $this->filesystemMetricsCache[$normalizedBasePath] = [
                'bytes' => $size,
                'fileCount' => 0,
            ];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isFile() && !$item->isLink()) {
                $fileCount++;
            }
        }

        return $this->filesystemMetricsCache[$normalizedBasePath] = [
            'bytes' => $size,
            'fileCount' => $fileCount,
        ];
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function getNonOverlappingPaths(array $paths): array
    {
        $normalizedPaths = [];
        foreach ($paths as $path) {
            $normalizedPaths[] = rtrim($path, '/');
        }

        usort(
            $normalizedPaths,
            static fn(string $left, string $right): int => strlen($left) <=> strlen($right),
        );

        $result = [];
        foreach ($normalizedPaths as $path) {
            $isCovered = false;
            foreach ($result as $existingPath) {
                if ($path === $existingPath || str_starts_with($path . '/', $existingPath . '/')) {
                    $isCovered = true;
                    break;
                }
            }

            if (!$isCovered) {
                $result[] = $path;
            }
        }

        return $result;
    }

    /**
     * @return array{
     *   storages: list<array{
     *     name: string,
     *     total: array{bytes: int, label: string},
     *     categories: list<array{
     *       identifier: string,
     *       iconIdentifier: string,
     *       label: string,
     *       bytes: int,
     *       formattedBytes: string,
     *       fileCount: int,
     *       sizeLabel: string
     *     }>
     *   }>
     * }
     */
    private function getMediaBreakdown(): array
    {
        $storageRows = [];

        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
            $rows = $queryBuilder
                ->select(
                    'deduplicated.storage',
                    'deduplicated.type',
                    'deduplicated.mime_type',
                    'deduplicated.extension',
                )
                ->addSelectLiteral('SUM(deduplicated.size) AS total_size')
                ->addSelectLiteral('COUNT(*) AS file_count')
                ->from('sys_file', 'deduplicated')
                ->where(
                    $queryBuilder->expr()->in(
                        'deduplicated.uid',
                        $this->buildLatestNonMissingSysFileUidSubquery(),
                    ),
                )
                ->groupBy('deduplicated.storage', 'deduplicated.type', 'deduplicated.mime_type', 'deduplicated.extension')
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($rows as $row) {
                $storageUid = (int)($row['storage'] ?? 0);
                if (!isset($storageRows[$storageUid])) {
                    $storageRows[$storageUid] = $this->createEmptyMediaCategories();
                }
                $category = $this->resolveMediaCategory(
                    (int)($row['type'] ?? 0),
                    (string)($row['mime_type'] ?? ''),
                    (string)($row['extension'] ?? ''),
                );
                $storageRows[$storageUid][$category]['bytes'] += max(0, (int)($row['total_size'] ?? 0));
                $storageRows[$storageUid][$category]['fileCount'] += max(0, (int)($row['file_count'] ?? 0));
            }
        } catch (\Throwable) {
            return ['storages' => []];
        }

        foreach ($this->getProcessedImagesMeasurementByStorage() as $storageUid => $processedFiles) {
            if (!isset($storageRows[$storageUid])) {
                $storageRows[$storageUid] = $this->createEmptyMediaCategories();
            }
            $storageRows[$storageUid][self::MEDIA_PROCESSED_IMAGES]['bytes'] = $processedFiles['bytes'];
            $storageRows[$storageUid][self::MEDIA_PROCESSED_IMAGES]['fileCount'] = $processedFiles['fileCount'];
        }

        $storages = [];
        foreach ($this->storageRepository->findAll() as $storage) {
            $categories = $storageRows[$storage->getUid()] ?? $this->createEmptyMediaCategories();
            $storageMeasurement = $this->getStorageMeasurement($storage);
            $categorizedBytes = array_sum(array_map(static fn(array $category): int => $category['bytes'], $categories));
            $categorizedFileCount = array_sum(array_map(static fn(array $category): int => $category['fileCount'], $categories));

            if ($storageMeasurement['bytes'] !== null && $storageMeasurement['bytes'] > $categorizedBytes) {
                $categories[self::MEDIA_OTHER]['bytes'] += $storageMeasurement['bytes'] - $categorizedBytes;
            }
            if ($storageMeasurement['fileCount'] !== null && $storageMeasurement['fileCount'] > $categorizedFileCount) {
                $categories[self::MEDIA_OTHER]['fileCount'] += $storageMeasurement['fileCount'] - $categorizedFileCount;
            }

            $categories = array_filter(
                $categories,
                static fn(array $category): bool => $category['bytes'] > 0,
            );
            uasort(
                $categories,
                static fn(array $left, array $right): int => $right['bytes'] <=> $left['bytes'],
            );

            $totalBytes = array_sum(array_map(static fn(array $category): int => $category['bytes'], $categories));
            foreach ($categories as &$category) {
                $category['formattedBytes'] = $this->formatBytes($category['bytes']);
                $category['sizeLabel'] = sprintf(
                    '%s (%s)',
                    $category['formattedBytes'],
                    $this->formatPercentage($category['bytes'], $totalBytes),
                );
            }
            unset($category);

            $storages[] = [
                'name' => $storage->getName() !== '' ? $storage->getName() : $this->translate('module.storageStatistics.unnamedStorage'),
                'total' => $this->createByteValue($totalBytes),
                'categories' => array_values($categories),
            ];
        }

        return ['storages' => $storages];
    }

    private function buildLatestNonMissingSysFileUidSubquery(): string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');

        return sprintf(
            '(%s)',
            $queryBuilder
                ->selectLiteral('MAX(uid)')
                ->from('sys_file')
                ->where('missing = 0')
                ->groupBy('storage', 'identifier')
                ->getSQL(),
        );
    }

    /**
     * @param array{
     *   storages: list<array{
     *     name: string,
     *     total: array{bytes: int, label: string},
     *     categories: list<array{identifier: string, iconIdentifier: string, label: string, bytes: int, formattedBytes: string, fileCount: int, sizeLabel: string}>
     *   }>
     * } $mediaBreakdown
     * @return array{bytes: int, label: string}
     */
    private function getMediaBreakdownTotal(array $mediaBreakdown): array
    {
        $bytes = array_sum(array_map(
            static fn(array $storage): int => $storage['total']['bytes'],
            $mediaBreakdown['storages'],
        ));

        return $this->createByteValue($bytes);
    }

    /**
     * @return array{
     *   connections: list<array{
     *     name: string,
     *     bytes: int|null,
     *     label: string,
     *     available: bool,
     *     tables: list<array{name: string, rowCount: int|null, rowCountLabel: string, bytes: int|null, formattedBytes: string, available: bool}>
     *   }>,
     *   total: array{bytes: int|null, label: string, available: bool}
     * }
     */
    private function getDatabaseOverview(): array
    {
        $connections = [];
        $totalBytes = 0;
        $hasAvailableConnection = false;

        foreach (array_keys($GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'] ?? []) as $connectionName) {
            $databaseConnection = $this->getSingleDatabaseOverview((string)$connectionName);
            $connections[] = ['name' => (string)$connectionName, ...$databaseConnection];

            if ($databaseConnection['available'] && $databaseConnection['bytes'] !== null) {
                $totalBytes += $databaseConnection['bytes'];
                $hasAvailableConnection = true;
            }
        }

        return [
            'connections' => $connections,
            'total' => $hasAvailableConnection
                ? [...$this->createByteValue($totalBytes), 'available' => true]
                : [...$this->createUnavailableValue(), 'available' => false],
        ];
    }

    /**
     * @return array{
     *   bytes: int|null,
     *   label: string,
     *   available: bool,
     *   tables: list<array{name: string, title: string|null, iconIdentifier: string, usesFallbackIcon: bool, rowCount: int|null, rowCountLabel: string, bytes: int|null, formattedBytes: string, sizeLabel: string, available: bool}>
     * }
     */
    private function getSingleDatabaseOverview(string $connectionName): array
    {
        try {
            $connection = $this->connectionPool->getConnectionByName($connectionName);
            $tables = $this->getDatabaseTablesOverview($connection);
            $availableTables = array_filter(
                $tables,
                static fn(array $table): bool => $table['available'] && $table['bytes'] !== null,
            );

            if ($availableTables !== []) {
                $bytes = array_sum(array_map(static fn(array $table): int => (int)$table['bytes'], $availableTables));
                foreach ($tables as &$table) {
                    if ($table['available'] && $table['bytes'] !== null) {
                        $table['sizeLabel'] = sprintf(
                            '%s (%s)',
                            $table['formattedBytes'],
                            $this->formatPercentage((int)$table['bytes'], $bytes),
                        );
                    }
                }
                unset($table);

                return [...$this->createByteValue($bytes), 'available' => true, 'tables' => $tables];
            }

            if ($tables !== []) {
                return [...$this->createUnavailableValue(), 'available' => false, 'tables' => $tables];
            }
        } catch (\Throwable) {
            return [...$this->createUnavailableValue(), 'available' => false, 'tables' => []];
        }

        return [...$this->createUnavailableValue(), 'available' => false, 'tables' => []];
    }

    /**
     * @return list<array{name: string, title: string|null, iconIdentifier: string, usesFallbackIcon: bool, rowCount: int|null, rowCountLabel: string, bytes: int|null, formattedBytes: string, sizeLabel: string, available: bool}>
     */
    private function getDatabaseTablesOverview(Typo3Connection $connection): array
    {
        $schemaManager = $connection->createSchemaManager();
        $tableNames = $schemaManager->listTableNames();
        $tableMetadata = $this->getDatabaseTableMetadata($connection, $tableNames);
        $tables = [];

        foreach ($tableNames as $tableName) {
            $rowCount = $tableMetadata[$tableName]['rowCount'] ?? $this->getTableRowCount($connection, $tableName);
            $bytes = $tableMetadata[$tableName]['bytes'] ?? null;
            $iconIdentifier = $this->getTableIconIdentifier($tableName);
            $tables[] = [
                'name' => $tableName,
                'title' => $this->getTableTitle($tableName),
                'iconIdentifier' => $iconIdentifier,
                'usesFallbackIcon' => $iconIdentifier === 'actions-database',
                'rowCount' => $rowCount,
                'rowCountLabel' => $rowCount !== null ? (string)$rowCount : $this->translate(self::NOT_AVAILABLE),
                'bytes' => $bytes,
                'formattedBytes' => $bytes !== null ? $this->formatBytes($bytes) : $this->translate('database.tableSizeNotAvailable'),
                'sizeLabel' => $bytes !== null ? $this->formatBytes($bytes) : $this->translate('database.tableSizeNotAvailable'),
                'available' => $bytes !== null,
            ];
        }

        $tables = array_values(array_filter(
            $tables,
            static fn(array $table): bool => $table['rowCount'] !== 0 && $table['bytes'] !== 0,
        ));

        usort($tables, static function (array $left, array $right): int {
            if ($left['bytes'] !== null && $right['bytes'] !== null) {
                return $right['bytes'] <=> $left['bytes'];
            }
            if ($left['bytes'] !== null) {
                return -1;
            }
            if ($right['bytes'] !== null) {
                return 1;
            }

            return strcasecmp($left['name'], $right['name']);
        });

        return $tables;
    }

    /**
     * @param list<string> $tableNames
     * @return array<string, array{bytes?: int, rowCount?: int}>
     */
    private function getDatabaseTableMetadata(Typo3Connection $connection, array $tableNames): array
    {
        $platform = $connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $databaseName = (string)($connection->getParams()['dbname'] ?? '');
            if ($databaseName === '') {
                return [];
            }

            $rows = $connection->fetchAllAssociative(
                'SELECT table_name, COALESCE(data_length + index_length, 0) AS size_bytes, COALESCE(table_rows, 0) AS table_rows FROM information_schema.TABLES WHERE table_schema = ?',
                [$databaseName],
            );

            $metadata = [];
            foreach ($rows as $row) {
                $metadata[(string)$row['table_name']] = [
                    'bytes' => (int)$row['size_bytes'],
                    'rowCount' => (int)$row['table_rows'],
                ];
            }

            return $metadata;
        }

        if ($platform instanceof PostgreSQLPlatform) {
            $metadata = [];
            foreach ($tableNames as $tableName) {
                $metadata[$tableName] = [
                    'bytes' => (int)$connection->fetchOne(
                    'SELECT pg_total_relation_size(?)',
                    [$tableName],
                    [Connection::PARAM_STR],
                    ),
                ];
            }

            return $metadata;
        }

        if ($platform instanceof SQLitePlatform) {
            return [];
        }

        return [];
    }

    private function getTableRowCount(Typo3Connection $connection, string $tableName): ?int
    {
        try {
            $queryBuilder = $connection->createQueryBuilder();
            $count = $queryBuilder
                ->selectLiteral('COUNT(*)')
                ->from($tableName)
                ->executeQuery()
                ->fetchOne();

            return (int)$count;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{group: string, path: string}>
     */
    private function getComposerInstallPaths(): array
    {
        $installedFile = Environment::getProjectPath() . '/vendor/composer/installed.json';
        if (!is_file($installedFile)) {
            return [];
        }

        $json = file_get_contents($installedFile);
        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $packages = $decoded['packages'] ?? $decoded;
        if (!is_array($packages)) {
            return [];
        }

        $seenPaths = [];
        $result = [];
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            $installPath = $package['install-path'] ?? null;
            if (!is_string($installPath) || $installPath === '') {
                continue;
            }

            $path = $this->normalizePath(dirname($installedFile) . '/' . $installPath);
            if ($path === null || isset($seenPaths[$path])) {
                continue;
            }

            $seenPaths[$path] = true;
            $result[] = [
                'group' => $this->resolvePackageGroup($package, $path),
                'path' => $path,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $package
     */
    private function resolvePackageGroup(array $package, string $path): string
    {
        $name = (string)($package['name'] ?? '');
        $type = (string)($package['type'] ?? '');
        $projectPath = Environment::getProjectPath();
        $vendorPath = $this->normalizePath($projectPath . '/vendor');

        if ($type === 'typo3-cms-extension' && !str_starts_with($path, $vendorPath . '/')) {
            return 'extensions';
        }

        if ($name === 'typo3/cms-core' || str_starts_with($name, 'typo3/cms-')) {
            return 'vendor';
        }

        return 'dependencies';
    }

    private function getPathSize(string $path): int
    {
        $systemMeasuredSize = $this->getPathSizeUsingSystemCommand($path);
        if ($systemMeasuredSize !== null) {
            return $systemMeasuredSize;
        }

        if (is_file($path)) {
            return (int)filesize($path);
        }

        if (!is_dir($path) || !is_readable($path)) {
            return 0;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        $size = 0;
        foreach ($iterator as $item) {
            if ($item->isFile() && !$item->isLink()) {
                $size += $item->getSize();
            }
        }

        return $size;
    }

    private function getPathSizeUsingSystemCommand(string $path): ?int
    {
        if (!is_file($path) && !is_dir($path)) {
            return null;
        }

        $commands = [
            ['du', '-sb', $path],
            ['du', '-sk', $path],
        ];

        foreach ($commands as $command) {
            try {
                $process = new Process($command);
                $process->setTimeout(10);
                $process->run();

                if (!$process->isSuccessful()) {
                    continue;
                }

                $output = trim($process->getOutput());
                if ($output === '') {
                    continue;
                }

                $firstColumn = preg_split('/\s+/', $output)[0] ?? null;
                if (!is_string($firstColumn) || !ctype_digit($firstColumn)) {
                    continue;
                }

                $value = (int)$firstColumn;
                if (($command[1] ?? null) === '-sk') {
                    $value *= 1024;
                }

                return max(0, $value);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function normalizePath(string $path): ?string
    {
        $realPath = realpath($path);
        if ($realPath === false) {
            return null;
        }

        return rtrim($realPath, '/');
    }

    /**
     * @return array{bytes: int, label: string}
     */
    private function createByteValue(int $bytes): array
    {
        return [
            'bytes' => $bytes,
            'label' => $this->formatBytes($bytes),
        ];
    }

    /**
     * @return array{bytes: null, label: string}
     */
    private function createUnavailableValue(): array
    {
        return [
            'bytes' => null,
            'label' => $this->translate(self::NOT_AVAILABLE),
        ];
    }

    /**
     * @return array{bytes: int, label: string, displayLabel: string, highlightClass: string, badgeClass: string}
     */
    private function createTotalValue(int $bytes): array
    {
        $value = $this->createByteValue($bytes);
        $maximumBytes = $this->getConfiguredMaximumTotalStorageBytes();

        if ($maximumBytes === null) {
            return [
                ...$value,
                'displayLabel' => $value['label'],
                'highlightClass' => '',
                'badgeClass' => '',
            ];
        }

        $percentage = $bytes / $maximumBytes * 100;
        $statusClass = $this->resolveTotalStatusClass($percentage);

        return [
            ...$value,
            'displayLabel' => sprintf(
                '%s / %s (%s%%)',
                $value['label'],
                $this->formatBytes($maximumBytes),
                number_format($percentage, 1, '.', ''),
            ),
            'highlightClass' => $statusClass !== '' ? 'text-' . $statusClass : '',
            'badgeClass' => $statusClass !== '' ? 'badge-' . $statusClass : '',
        ];
    }

    /**
     * @param array{
     *   items: list<array{name: string, bytes: int|null, label: string}>,
     *   total: array{bytes: int|null, label: string, available: bool}
     * } $storages
     * @param array{
     *   connections: list<array{name: string, bytes: int|null, label: string, available: bool}>,
     *   total: array{bytes: int|null, label: string, available: bool}
     * } $database
     * @param array<string, array{bytes: int|null, label: string}> $code
     * @param array{bytes: int, label: string} $misc
     * @return array{
     *   categories: list<array{
     *     identifier: string,
     *     label: string,
     *     bytes: int,
     *     formattedBytes: string,
     *     percentage: float,
     *     colorClass: string
     *   }>,
     *   maximumBytes: int|null,
     *   referenceBytes: int,
     *   totalPercentage: float,
     *   availableBytes: int|null,
     *   availablePercentage: float|null,
     *   availableLabel: string|null,
     *   showAvailableSegment: bool,
     *   isMaximumConfigured: bool
     * }
     */
    private function createChartData(array $storages, array $database, array $code, array $misc, int $totalBytes): array
    {
        $maximumBytes = $this->getConfiguredMaximumTotalStorageBytes();
        $visualReferenceBytes = $maximumBytes !== null
            ? max($maximumBytes, $totalBytes, 1)
            : max($totalBytes, 1);
        $categories = [
            $this->createChartCategory(
                'storages',
                $this->translate('section.fileadmin'),
                (int)($storages['total']['bytes'] ?? 0),
                'size-storage-color-media',
                $visualReferenceBytes,
            ),
            $this->createChartCategory(
                'database',
                $this->translate('section.database'),
                (int)($database['total']['bytes'] ?? 0),
                'size-storage-color-database',
                $visualReferenceBytes,
            ),
            $this->createChartCategory(
                'code',
                $this->translate('section.code'),
                (int)($code['total']['bytes'] ?? 0),
                'size-storage-color-code',
                $visualReferenceBytes,
            ),
            $this->createChartCategory(
                'misc',
                $this->translate('section.misc'),
                $misc['bytes'],
                'size-storage-color-misc',
                $visualReferenceBytes,
            ),
        ];

        $showAvailableSegment = $maximumBytes !== null && $totalBytes < $maximumBytes;
        $availableBytes = $showAvailableSegment ? $maximumBytes - $totalBytes : null;

        return [
            'categories' => $categories,
            'maximumBytes' => $maximumBytes,
            'referenceBytes' => $visualReferenceBytes,
            'totalPercentage' => $maximumBytes !== null && $maximumBytes > 0 ? ($totalBytes / $maximumBytes * 100) : 100.0,
            'availableBytes' => $availableBytes,
            'availablePercentage' => $availableBytes !== null ? ($availableBytes / $visualReferenceBytes * 100) : null,
            'availableLabel' => $availableBytes !== null ? $this->formatBytes($availableBytes) : null,
            'showAvailableSegment' => $showAvailableSegment,
            'isMaximumConfigured' => $maximumBytes !== null,
        ];
    }

    /**
     * @return array{
     *   identifier: string,
     *   label: string,
     *   bytes: int,
     *   formattedBytes: string,
     *   percentage: float,
     *   colorClass: string
     * }
     */
    private function createChartCategory(
        string $identifier,
        string $label,
        int $bytes,
        string $colorClass,
        int $referenceBytes,
    ): array {
        return [
            'identifier' => $identifier,
            'label' => $label,
            'bytes' => $bytes,
            'formattedBytes' => $this->formatBytes($bytes),
            'percentage' => $bytes > 0 ? ($bytes / max($referenceBytes, 1) * 100) : 0.0,
            'colorClass' => $colorClass,
        ];
    }

    private function resolveTotalStatusClass(float $percentage): string
    {
        if ($percentage > 100.0) {
            return 'danger';
        }

        if ($percentage > 90.0) {
            return 'warning';
        }

        return '';
    }

    private function getConfiguredMaximumTotalStorageBytes(): ?int
    {
        try {
            $configuration = $this->extensionConfiguration->get('size');
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($configuration)) {
            return null;
        }

        $configuredValue = trim((string)($configuration['maximumTotalStorage'] ?? ''));
        if ($configuredValue === '') {
            return null;
        }

        return $this->parseSizeStringToBytes($configuredValue);
    }

    private function parseSizeStringToBytes(string $value): ?int
    {
        if (!preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*(B|KB|MB|GB|TB)\s*$/i', $value, $matches)) {
            return null;
        }

        $numericValue = (float)str_replace(',', '.', $matches[1]);
        if ($numericValue <= 0) {
            return null;
        }

        $unit = strtoupper($matches[2]);
        $unitMap = [
            'B' => 0,
            'KB' => 1,
            'MB' => 2,
            'GB' => 3,
            'TB' => 4,
        ];

        $power = $unitMap[$unit] ?? null;
        if ($power === null) {
            return null;
        }

        $bytes = (int)round($numericValue * (1024 ** $power));

        return $bytes > 0 ? $bytes : null;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float)$bytes;
        $unitIndex = 0;

        while ($value >= 1024 && isset($units[$unitIndex + 1])) {
            $value /= 1024;
            $unitIndex++;
        }

        $precision = $unitIndex === 0 ? 0 : 2;
        $formattedValue = number_format($value, $precision, '.', ' ');

        if ($precision > 0) {
            $formattedValue = rtrim(rtrim($formattedValue, '0'), '.');
        }

        return $formattedValue . ' ' . $units[$unitIndex];
    }

    private function formatPercentage(int $bytes, int $totalBytes): string
    {
        $percentage = $totalBytes > 0 ? ($bytes / $totalBytes * 100) : 0.0;

        return number_format($percentage, 1, '.', '') . '%';
    }

    private function translate(string $key): string
    {
        return $this->languageServiceFactory
            ->createFromUserPreferences($GLOBALS['BE_USER'] ?? null)
            ->sL('LLL:EXT:size/Resources/Private/Language/locallang.xlf:' . $key) ?: $key;
    }

    private function resolveLabel(string $label): string
    {
        $languageService = $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);

        if (str_starts_with($label, 'LLL:')) {
            return $languageService->sL($label) ?: $label;
        }

        if (preg_match('/^([a-z0-9_.-]+):([a-z0-9_.-]+)$/i', $label, $matches) === 1) {
            return (string)($languageService->translate($matches[2], $matches[1]) ?? $label);
        }

        return $label;
    }

    /**
     * @return list<string>
     */
    private function getMediaCategoryOrder(): array
    {
        return [
            self::MEDIA_IMAGES,
            self::MEDIA_VIDEOS,
            self::MEDIA_DOCUMENTS,
            self::MEDIA_AUDIO,
            self::MEDIA_ARCHIVES,
            self::MEDIA_OTHER,
            self::MEDIA_PROCESSED_IMAGES,
        ];
    }

    private function resolveMediaCategory(int $fileType, string $mimeType, string $extension): string
    {
        $normalizedMimeType = strtolower(trim($mimeType));
        $normalizedExtension = strtolower(trim($extension, ". \t\n\r\0\x0B"));

        return match ($fileType) {
            FileType::IMAGE->value => self::MEDIA_IMAGES,
            FileType::VIDEO->value => self::MEDIA_VIDEOS,
            FileType::AUDIO->value => self::MEDIA_AUDIO,
            FileType::TEXT->value => self::MEDIA_DOCUMENTS,
            FileType::APPLICATION->value => $this->resolveApplicationMediaCategory($normalizedMimeType, $normalizedExtension),
            default => self::MEDIA_OTHER,
        };
    }

    private function resolveApplicationMediaCategory(string $mimeType, string $extension): string
    {
        if ($this->isDocumentMimeType($mimeType) || $this->isDocumentExtension($extension)) {
            return self::MEDIA_DOCUMENTS;
        }

        if ($this->isArchiveMimeType($mimeType) || $this->isArchiveExtension($extension)) {
            return self::MEDIA_ARCHIVES;
        }

        return self::MEDIA_OTHER;
    }

    private function isDocumentMimeType(string $mimeType): bool
    {
        if ($mimeType === '') {
            return false;
        }

        return str_starts_with($mimeType, 'text/')
            || in_array($mimeType, [
                'application/pdf',
                'application/rtf',
                'application/msword',
                'application/vnd.ms-excel',
                'application/vnd.ms-powerpoint',
                'application/vnd.oasis.opendocument.text',
                'application/vnd.oasis.opendocument.spreadsheet',
                'application/vnd.oasis.opendocument.presentation',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.apple.pages',
                'application/vnd.apple.numbers',
                'application/vnd.apple.keynote',
                'application/epub+zip',
                'application/json',
                'application/xml',
                'application/yaml',
            ], true);
    }

    private function isDocumentExtension(string $extension): bool
    {
        return in_array($extension, [
            'txt', 'text', 'md', 'rst', 'rtf', 'html', 'htm', 'pdf', 'doc', 'docx', 'dot', 'dotx',
            'xls', 'xlsx', 'csv', 'ods', 'ppt', 'pptx', 'odp', 'odt', 'pages',
            'numbers', 'key', 'epub', 'json', 'xml', 'yml', 'yaml',
        ], true);
    }

    private function isArchiveMimeType(string $mimeType): bool
    {
        if ($mimeType === '') {
            return false;
        }

        return in_array($mimeType, [
            'application/zip',
            'application/x-zip-compressed',
            'application/x-tar',
            'application/gzip',
            'application/x-gzip',
            'application/x-7z-compressed',
            'application/x-rar-compressed',
            'application/vnd.rar',
            'application/x-bzip2',
            'application/x-xz',
        ], true);
    }

    private function isArchiveExtension(string $extension): bool
    {
        return in_array($extension, [
            'zip', 'tar', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar',
        ], true);
    }

    /**
     * @return array{bytes: int, fileCount: int}
     */
    private function getProcessedImagesMeasurementByStorage(): array
    {
        $measurements = [];

        foreach ($this->storageRepository->findAll() as $storage) {
            $processingPaths = $this->getLocalProcessingFolderPaths($storage);
            if ($processingPaths === []) {
                continue;
            }

            $storageUid = $storage->getUid();
            $measurements[$storageUid] = ['bytes' => 0, 'fileCount' => 0];
            foreach ($this->getNonOverlappingPaths($processingPaths) as $processingPath) {
                $metrics = $this->getFilesystemStorageMetrics($processingPath);
                $measurements[$storageUid]['bytes'] += $metrics['bytes'];
                $measurements[$storageUid]['fileCount'] += $metrics['fileCount'];
            }
        }

        return $measurements;
    }

    /**
     * @return list<string>
     */
    private function getLocalProcessingFolderPaths(ResourceStorage $storage): array
    {
        if ($storage->getDriverType() !== 'Local') {
            return [];
        }

        try {
            $paths = [];
            foreach ($storage->getProcessingFolders() as $processingFolder) {
                $resolvedPath = $this->resolveLocalFolderPath($processingFolder);
                if ($resolvedPath !== null) {
                    $paths[] = $resolvedPath;
                }
            }

            return $paths;
        } catch (\Throwable) {
            return [];
        }
    }

    private function resolveLocalFolderPath(Folder $folder): ?string
    {
        $identifier = $folder->getIdentifier();
        if ($identifier === '') {
            return null;
        }

        $storageBasePath = $this->resolveLocalStorageBasePath($folder->getStorage());
        if ($storageBasePath === null) {
            return null;
        }

        $resolvedPath = $this->normalizePath($storageBasePath . '/' . ltrim($identifier, '/'));

        return $resolvedPath !== null && is_dir($resolvedPath) && is_readable($resolvedPath) ? $resolvedPath : null;
    }

    /**
     * @return array<string, array{identifier: string, iconIdentifier: string, label: string, bytes: int, formattedBytes: string, fileCount: int, sizeLabel: string}>
     */
    private function createEmptyMediaCategories(): array
    {
        $categories = [];
        foreach ($this->getMediaCategoryOrder() as $identifier) {
            $categories[$identifier] = [
                'identifier' => $identifier,
                'iconIdentifier' => $this->getMediaCategoryIconIdentifier($identifier),
                'label' => $this->translate('media.' . $identifier),
                'bytes' => 0,
                'formattedBytes' => $this->formatBytes(0),
                'fileCount' => 0,
                'sizeLabel' => $this->formatBytes(0) . ' (0.0%)',
            ];
        }

        return $categories;
    }

    private function getMediaCategoryIconIdentifier(string $identifier): string
    {
        return match ($identifier) {
            self::MEDIA_IMAGES => 'actions-file-image',
            self::MEDIA_VIDEOS => 'actions-file-video',
            self::MEDIA_DOCUMENTS => 'actions-file-pdf',
            self::MEDIA_AUDIO => 'actions-file-audio',
            self::MEDIA_ARCHIVES => 'actions-archive',
            self::MEDIA_PROCESSED_IMAGES => 'form-image-upload',
            self::MEDIA_OTHER => 'actions-file',
            default => 'actions-file',
        };
    }

    private function getTableIconIdentifier(string $tableName): string
    {
        $ctrl = $GLOBALS['TCA'][$tableName]['ctrl'] ?? null;
        if (!is_array($ctrl)) {
            return 'actions-database';
        }

        $typeIconClasses = $ctrl['typeicon_classes'] ?? null;
        if (is_array($typeIconClasses) && is_string($typeIconClasses['default'] ?? null) && $typeIconClasses['default'] !== '') {
            return $typeIconClasses['default'];
        }

        if (is_string($ctrl['iconfile'] ?? null) && $ctrl['iconfile'] !== '') {
            return 'tcarecords-' . $tableName . '-default';
        }

        return 'actions-database';
    }

    private function getTableTitle(string $tableName): ?string
    {
        $ctrl = $GLOBALS['TCA'][$tableName]['ctrl'] ?? null;
        if (!is_array($ctrl)) {
            return null;
        }

        $title = $ctrl['title'] ?? null;
        if (!is_string($title) || $title === '') {
            return null;
        }

        $resolvedTitle = trim($this->resolveLabel($title));

        if ($resolvedTitle === '' || $resolvedTitle === $tableName) {
            return null;
        }

        return $resolvedTitle;
    }
}
