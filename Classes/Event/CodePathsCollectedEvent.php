<?php

declare(strict_types = 1);

namespace T3\Size\Event;

final class CodePathsCollectedEvent
{
    /**
     * @param list<array{group: string, path: string}> $paths
     */
    public function __construct(
        public array $paths,
    ) {
    }
}
