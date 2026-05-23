<?php

declare(strict_types = 1);

namespace T3\Size\Event;

use TYPO3\CMS\Core\Resource\ResourceStorage;

final class StorageProcessingPathsCollectedEvent
{
    /**
     * @param list<string> $paths
     */
    public function __construct(
        public array $paths,
        public readonly ResourceStorage $storage,
    ) {
    }
}
