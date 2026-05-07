<?php

declare(strict_types=1);

namespace T3\Size\Service;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use Symfony\Component\Finder\Finder;

final class SizeOverviewProvider
{
    private const NOT_AVAILABLE = 'notAvailable';

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
     *   storages: array{
     *     items: list<array{name: string, bytes: int|null, label: string}>,
     *     total: array{bytes: int|null, label: string, available: bool}
     *   },
     *   database: array{
     *     connections: list<array{name: string, bytes: int|null, label: string, available: bool}>,
     *     total: array{bytes: int|null, label: string, available: bool}
     *   },
     *   total: array{bytes: int, label: string, displayLabel: string, highlightClass: string, badgeClass: string}
     * }
     */
    public function getOverview(): array
    {
        $code = $this->getCodeOverview();
        $misc = $this->getMiscOverview();
        $storages = $this->getStorageOverview();
        $database = $this->getDatabaseOverview();
        $totalBytes = (
            ($code['total']['bytes'] ?? 0)
            + ($misc['bytes'] ?? 0)
            + ($storages['total']['bytes'] ?? 0)
            + ($database['total']['bytes'] ?? 0)
        );
        $total = $this->createTotalValue($totalBytes);

        return [
            'code' => $code,
            'misc' => $misc,
            'storages' => $storages,
            'database' => $database,
            'total' => $total,
        ];
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
        $size = 0;

        foreach (new \FilesystemIterator($projectPath, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isFile() && !$item->isLink()) {
                $size += $item->getSize();
            }
        }

        $size += $this->getPathSize($projectPath . '/var');
        $size += $this->getPathSize($projectPath . '/config');

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
     * @return array{bytes: int|null, label: string, paths: list<string>}
     */
    private function getStorageMeasurement(ResourceStorage $storage): array
    {
        try {
            if (!$storage->isOnline()) {
                return [...$this->createUnavailableValue(), 'paths' => []];
            }

            $paths = $this->getStorageMeasuredPaths($storage);
            if ($paths === []) {
                return [...$this->createUnavailableValue(), 'paths' => []];
            }

            $size = 0;
            foreach ($paths as $path) {
                $size += $this->getFilesystemStorageSize($path);
            }

            return [...$this->createByteValue($size), 'paths' => $paths];
        } catch (\Throwable) {
            return [...$this->createUnavailableValue(), 'paths' => []];
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
        $finder = new Finder();
        $finder->files()->in($basePath)->ignoreUnreadableDirs();

        $size = 0;
        foreach ($finder as $file) {
            $size += $file->getSize();
        }

        return $size;
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
     *   connections: list<array{name: string, bytes: int|null, label: string, available: bool}>,
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
     * @return array{bytes: int|null, label: string, available: bool}
     */
    private function getSingleDatabaseOverview(string $connectionName): array
    {
        try {
            $connection = $this->connectionPool->getConnectionByName($connectionName);
            $platform = $connection->getDatabasePlatform();

            if ($platform instanceof AbstractMySQLPlatform) {
                $databaseName = (string)($connection->getParams()['dbname'] ?? '');
                if ($databaseName === '') {
                    return [...$this->createUnavailableValue(), 'available' => false];
                }

                $bytes = $connection->fetchOne(
                    'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = ?',
                    [$databaseName],
                );

                return [...$this->createByteValue((int)$bytes), 'available' => true];
            }

            if ($platform instanceof PostgreSQLPlatform) {
                $bytes = $connection->fetchOne('SELECT pg_database_size(current_database())');

                return [...$this->createByteValue((int)$bytes), 'available' => true];
            }

            if ($platform instanceof SQLitePlatform) {
                $params = $connection->getParams();
                $path = (string)($params['path'] ?? '');
                if ($path === '' || !is_file($path) || !is_readable($path)) {
                    return [...$this->createUnavailableValue(), 'available' => false];
                }

                return [...$this->createByteValue((int)filesize($path)), 'available' => true];
            }
        } catch (\Throwable) {
            return [...$this->createUnavailableValue(), 'available' => false];
        }

        return [...$this->createUnavailableValue(), 'available' => false];
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

        return number_format($value, $precision, '.', ' ') . ' ' . $units[$unitIndex];
    }

    private function translate(string $key): string
    {
        return $this->languageServiceFactory
            ->createFromUserPreferences($GLOBALS['BE_USER'] ?? null)
            ->sL('LLL:EXT:size/Resources/Private/Language/locallang.xlf:' . $key) ?: $key;
    }
}
