# AGENTS.md

This repository contains a TYPO3 extension with Composer type `typo3-cms-extension`.

## Project Context

- Extension key: `size`
- Composer package: `t3/size`
- Minimum PHP version: `8.2`
- Supported TYPO3 versions: `13.4` and `14.3`
- Available local TYPO3 environments:
  - Composer mode: `v13`, `v14`
  - Classic mode: `v13-classic`, `v14-classic`
- The extension itself is mounted to: `/var/www/size` (relevant for code quality checks)
- PHP namespace root: `T3\\Size\\`

## Mandatory Rules

- All implementations must be compatible with TYPO3 `13.4` and TYPO3 `14.3`.
- All implementations must be compatible with PHP `8.2` or higher.
- Prefer TYPO3 APIs and patterns that work cleanly in both supported TYPO3 versions.
- TYPO3 standards and naming conventions must be followed.
- Do not introduce deprecated APIs, legacy hooks, or outdated utility usage when a current TYPO3 13/14 compatible alternative exists.
- Prefer modern TYPO3 core patterns, especially dependency injection, PSR-compliant code, strict typing, and current namespaces.
- Do not write to `ext_tables.sql` for TCA columns and tables which get automatic schema updates. Only use it when specific manual SQL changes are required.
- Keep all changes compatible with this extension's Composer setup and PSR-4 autoloading structure.

## Command Execution

- When commands must be executed for the TYPO3 project, use `ddev exec`.
- To run Composer scripts for this extension, connect to the DDEV container, change to `/var/www/size`, and execute `composer run <scriptname>` there.
- Code quality tools added via Composer scripts must be executed after relevant PHP code changes.
- In particular, mention and use `phpcs:fix` for coding standards fixes and `phpstan` for static analysis when PHP code was changed:
```bash
ddev exec bash -lc "cd ../size && composer run phpcs:fix"
ddev exec bash -lc "cd ../size && composer run phpstan"
```
- When database queries must be executed via DDEV, explicitly target the intended database schema (`v13`, `v14`, `v13_classic`, or `v14_classic`). Do not assume TYPO3 tables are in the default `db` schema.
- Important: inside the container, TYPO3 project environments are located in these subdirectories:
  - Composer mode: `v13` and `v14`
  - Classic mode: `v13-classic` and `v14-classic`
- Important: Classic mode uses the webroot directly in the environment directory and stores configuration in `typo3conf/system/`. Composer mode uses `public/` as webroot and stores configuration in `config/system/`.
- Therefore, TYPO3-related commands must be executed relative to the intended environment, for example:

```bash
ddev exec bash -lc "cd v13 && php vendor/bin/typo3 list"
ddev exec bash -lc "cd v14 && php vendor/bin/typo3 list"
ddev exec bash -lc "cd v13-classic && php typo3/sysext/core/bin/typo3 list"
ddev exec bash -lc "cd v14-classic && php typo3/sysext/core/bin/typo3 list"
ddev exec bash -lc "cd v13 && composer update"
ddev exec bash -lc "cd v14 && composer update"
ddev mysql v13 -e "SHOW TABLES LIKE 'sys_file';"
ddev mysql v14 -e "SHOW TABLES LIKE 'sys_file';"
ddev mysql v13_classic -e "SHOW TABLES LIKE 'sys_file';"
ddev mysql v14_classic -e "SHOW TABLES LIKE 'sys_file';"
```

- Avoid running TYPO3-specific project commands directly on the host if the same task should run inside the container.
- If a change must be verified against TYPO3 behavior, consider whether it should be checked in Composer mode, Classic mode, or both major versions.
- If constructor signatures, dependency injection wiring, service definitions, or labels in localization are changed, flush TYPO3 caches in all affected environments:

```bash
ddev exec bash -lc "cd v13 && php vendor/bin/typo3 cache:flush"
ddev exec bash -lc "cd v14 && php vendor/bin/typo3 cache:flush"
ddev exec bash -lc "cd v13-classic && php typo3/sysext/core/bin/typo3 cache:flush"
ddev exec bash -lc "cd v14-classic && php typo3/sysext/core/bin/typo3 cache:flush"
```

- To provision the local instances, use the provided DDEV commands instead of rebuilding the setup manually:

```bash
ddev install-v13
ddev install-v14
ddev install-v13-classic
ddev install-v14-classic
ddev install-all
```

## Code and Structure

- New PHP classes belong in `Classes/` and must follow the `T3\\Size\\...` namespace structure.
- Public APIs inside the extension should use clear type declarations whenever possible.
- When adding features or refactoring behavior, check whether `Documentation/` must be updated as well.
- Do not overwrite or revert existing files or user changes without a clear reason.
- Do not add or require unit tests unless explicitly requested by the user.

## TYPO3 API Decision Rule

If there are multiple ways to implement a TYPO3 task, choose the approach that matches current TYPO3 core style and remains compatible with both TYPO3 `13.4` and `14.3`.

## Guidance for AI Agents

- Optimize for maintainable TYPO3 code that works in both supported TYPO3 versions, not for a single-version solution.
- Before using a TYPO3 API, prefer the current core-recommended approach that is available in TYPO3 `13.4` and `14.3`.
- Keep changes minimal, consistent with the existing extension structure, and easy to review.
- Do not introduce unit tests by default.
- Do not run git commands in parallel; execute them sequentially to avoid `index.lock` conflicts.
- Write commit messages as `[TYPE] Summary` and create AI-authored commits as `Codex CLI <armin@v.ieweg.de>`.
