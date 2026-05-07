# EXT:size 

TYPO3 CMS extension to display TYPO3 CMS storage usage information in the backend.

## Configuration

The optional extension setting `maximumTotalStorage` can be used to define a limit for the total value shown in the backend toolbar dropdown.

The toolbar dropdown also links to an extended backend module with a colored storage distribution bar for the main categories `Media`, `Database`, `Code`, and `Misc`.

Examples:

- `250 MB`
- `1 GB`
- `1.5 GB`

If set, the total section is rendered like `Total: 165.32 MB / 250 MB (66.1%)`.

If `maximumTotalStorage` is not set, the module visualization always renders the bar fully filled and scales the category segments relative to the currently measured total.
