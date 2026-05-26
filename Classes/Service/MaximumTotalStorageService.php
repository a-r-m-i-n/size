<?php

declare(strict_types = 1);

namespace T3\Size\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final readonly class MaximumTotalStorageService
{
    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    public function getMaximumTotalStorageBytes(): ?int
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
        if ('' === $configuredValue) {
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
        if (null === $power) {
            return null;
        }

        $bytes = (int)round($numericValue * (1024 ** $power));

        return $bytes > 0 ? $bytes : null;
    }
}
