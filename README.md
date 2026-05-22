# EXT:size 

TYPO3 CMS extension to display TYPO3 CMS storage usage information in the backend.

The extension provides:

- a backend toolbar item with storage totals
- a dashboard widget
- an extended backend module with a visual storage overview
- a persisted storage snapshot, so regular backend page loads do not trigger an expensive recalculation
- a manual refresh action in the backend module
- a CLI command for Scheduler or manual execution

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
- the runtime of the last successful calculation
- an indicator if a recalculation is currently running
- a `Recalculate` action next to the update metadata, if enabled by extension settings

The `Misc` category includes files in the project root as well as the `config`, `var`, and `public/typo3temp` directories.

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

### `enableManualRefreshButton`

The boolean extension setting `enableManualRefreshButton` controls whether the manual `Recalculate` action is shown in the backend module.

Default:

```text
1
```

Behavior:

- if enabled, editors can trigger a synchronous recalculation in the backend module
- if disabled, the button is hidden and direct access to the module refresh route is rejected
- the CLI command `size:refresh` remains available regardless of this setting
