# EXT:size 

TYPO3 CMS extension to display TYPO3 CMS storage usage information in the backend.

The extension provides:

- a backend toolbar item with storage totals
- a dashboard widget
- an extended backend module with a visual storage overview
- a persisted storage snapshot, so regular backend page loads do not trigger an expensive recalculation
- a PSR-14 event before the snapshot is stored, so listeners can adjust the calculated overview
- a manual refresh action in the backend module
- a CLI command for Scheduler or manual execution
- optional warning/full email notifications when a configured storage limit is exceeded

## How It Works

The toolbar item, dashboard widget, and backend module read the latest persisted storage snapshot.

Storage statistics are not recalculated while rendering these views.

Instead, the snapshot is updated explicitly:

- in the backend module via the `Recalculate` action
- on the command line via `size:refresh`
- via TYPO3 Scheduler by using the same command

If no snapshot exists yet, the UI renders placeholder values until the first refresh has been executed.

The storage snapshot contains:

- the calculated overview data
- the timestamp of the last successful calculation
- the runtime of the last successful calculation

To avoid duplicate expensive runs, refreshes are protected by a TYPO3 lock. If another refresh is already running, a second one is skipped.

## Backend Module

The backend module shows:

- a colored storage distribution bar for the main categories `Media`, `Database`, `Code`, and `Misc`
- the age of the last successful calculation
- for backend administrators, the runtime of the last successful calculation
- for backend administrators, the notification result of the last refresh check
- an indicator if a recalculation is currently running
- for backend administrators, a `Recalculate` action next to the update metadata

The `Misc` category includes files in the project root as well as the `config`, `var`, and `public/typo3temp` directories.

Additional project folders can be configured and are then shown as separate rows in the `Misc` breakdown. Their sizes are included in the `Misc` total and therefore also in the chart and overall `Total`.

## CLI / Scheduler

The extension provides the Symfony command:

```bash
php vendor/bin/typo3 size:refresh
```

This command:

- recalculates the storage snapshot
- stores the new result persistently
- exits with a non-success status if another refresh is already running

This makes it suitable for TYPO3 Scheduler jobs.

## Configuration

### `maximumTotalStorage`

The optional extension setting `maximumTotalStorage` can be used to define a limit for the total value shown in the backend toolbar dropdown and module visualization.

Examples:

- `250 MB`
- `1 GB`
- `1.5 GB`

If set, the total section is rendered like `Total: 165.32 MB / 250 MB (66.1%)`.

If `maximumTotalStorage` is not set, the module visualization always renders the bar fully filled and scales the category segments relative to the currently measured total.

### `warningNotificationRecipients`

Optional comma- or line-separated email addresses that receive a warning mail when the measured total is above `90%` and below `100%` of `maximumTotalStorage`.

Each warning notification has a cooldown of 7 days.

### `fullNotificationRecipients`

Optional comma- or line-separated email addresses that receive a full mail when the measured total is at or above `100%` of `maximumTotalStorage`.

Each full notification has a separate cooldown of 7 days.

If an email address is configured in both recipient lists and the usage is at or above `100%`, it only receives the full notification.

### `additionalMiscFolders`

Optional comma- or line-separated relative project paths that should be measured as additional `Misc` rows.

Examples:

- `packages`
- `public/uploads`
- `Build/cache`

Configured paths must point into the TYPO3 project directory. Existing paths are normalized via `realpath()`, and symlink targets outside the project are ignored. Missing paths stay visible in the `Misc` table with `0 B`.

The manual backend-module refresh is restricted to backend administrators. Non-admin users still see the last update timestamp and refresh status, but they do not see the runtime or the `Recalculate` action. The CLI command `size:refresh` remains available regardless of backend permissions.
