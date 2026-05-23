<?php

declare(strict_types = 1);

namespace T3\Size\Service;

final readonly class ByteFormatter
{
    public function format(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float)$bytes;
        $unitIndex = 0;

        while ($value >= 1024 && isset($units[$unitIndex + 1])) {
            $value /= 1024;
            ++$unitIndex;
        }

        $precision = 0 === $unitIndex ? 0 : 2;
        $formattedValue = number_format($value, $precision, '.', ' ');

        if ($precision > 0) {
            $formattedValue = rtrim(rtrim($formattedValue, '0'), '.');
        }

        return $formattedValue . ' ' . $units[$unitIndex];
    }
}
