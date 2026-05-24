# EventListeners

The extension exposes several PSR-14 events for adjusting collected filesystem paths before size calculation continues, and one event for modifying the calculated overview immediately before the snapshot is stored.

For the path-collection events, listeners can read the current payload and replace it completely by assigning a new array to the public `paths` property.

## `T3\Size\Event\CodePathsCollectedEvent`

Dispatched after code paths from Composer and classic extensions have been collected and before filesystem metrics are calculated.

`$event->paths` contains:

- `list<array{group: string, path: string}>`

The `group` value is one of `vendor`, `extensions`, or `dependencies`.

## `T3\Size\Event\AdditionalMiscPathsCollectedEvent`

Dispatched after configured additional `Misc` folders have been resolved and before their metrics are calculated.

`$event->paths` contains:

- `list<string>` with filesystem paths

Additional context:

- `$event->projectPath` contains the normalized TYPO3 project path

## `T3\Size\Event\StoragePathsCollectedEvent`

Dispatched for each FAL storage after local base paths have been collected and before storage metrics are calculated.

`$event->paths` contains:

- `list<string>` with filesystem paths

Additional context:

- `$event->storage` contains the current `ResourceStorage`

## `T3\Size\Event\StorageProcessingPathsCollectedEvent`

Dispatched for each FAL storage after local processing / processed-image paths have been collected and before processed-image metrics are calculated.

`$event->paths` contains:

- `list<string>` with filesystem paths

Additional context:

- `$event->storage` contains the current `ResourceStorage`

## `T3\Size\Event\BeforeSizeOverviewSnapshotStoredEvent`

Dispatched immediately before the calculated snapshot is stored.

The event allows listeners to inspect or replace the overview payload before it is persisted.

Methods and payload:

- `getOverview()` returns `array<string, mixed>` with the calculated overview
- `setOverview(array<string, mixed> $overview)` replaces the overview that will be stored
- `getCalculatedAt()` returns the calculation timestamp as Unix time
- `getDurationMs()` returns the calculation runtime in milliseconds

## Listener Example

The following listener replaces the collected additional `Misc` paths completely:

```php
<?php

declare(strict_types=1);

namespace Vendor\SitePackage\EventListener;

use T3\Size\Event\AdditionalMiscPathsCollectedEvent;

final class ReplaceAdditionalMiscPathsListener
{
    public function __invoke(AdditionalMiscPathsCollectedEvent $event): void
    {
        $event->paths = [
            $event->projectPath . '/packages',
            $event->projectPath . '/Build/cache',
        ];
    }
}
```
