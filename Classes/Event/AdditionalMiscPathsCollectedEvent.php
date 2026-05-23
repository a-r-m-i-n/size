<?php

declare(strict_types = 1);

namespace T3\Size\Event;

final class AdditionalMiscPathsCollectedEvent
{
    /**
     * @param list<string> $paths
     */
    public function __construct(
        public array $paths,
        public readonly string $projectPath,
    ) {
    }
}
