#!/usr/bin/env php
<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$composerJsonFile = $projectRoot . '/composer.json';

if (!extension_loaded('zip')) {
    fwrite(STDERR, "The PHP zip extension is required.\n");
    exit(1);
}

$composerJson = @file_get_contents($composerJsonFile);
if ($composerJson === false) {
    fwrite(STDERR, "Unable to read composer.json.\n");
    exit(1);
}

try {
    /** @var array<string, mixed> $composerConfiguration */
    $composerConfiguration = json_decode($composerJson, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, 'Invalid composer.json: ' . $exception->getMessage() . "\n");
    exit(1);
}

$version = $composerConfiguration['version'] ?? null;
if (!is_string($version) || $version === '') {
    fwrite(STDERR, "composer.json must contain a non-empty string version.\n");
    exit(1);
}

$zipFileName = sprintf('size_%s.zip', $version);
$zipFilePath = $projectRoot . '/' . $zipFileName;

if (is_file($zipFilePath) && !unlink($zipFilePath)) {
    fwrite(STDERR, sprintf('Unable to remove existing archive "%s".', $zipFileName) . "\n");
    exit(1);
}

$includePaths = [
    'Classes',
    'Configuration',
    'Resources',
    'composer.json',
    'ext_emconf.php',
    'ext_conf_template.txt',
    'README.md',
];

$zipArchive = new ZipArchive();
$openResult = $zipArchive->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
if ($openResult !== true) {
    fwrite(STDERR, sprintf('Unable to create archive "%s" (ZipArchive error %s).', $zipFileName, (string)$openResult) . "\n");
    exit(1);
}

foreach ($includePaths as $includePath) {
    $sourcePath = $projectRoot . '/' . $includePath;

    if (is_dir($sourcePath)) {
        addDirectoryToZip($zipArchive, $sourcePath, $projectRoot);
        continue;
    }

    if (is_file($sourcePath)) {
        addFileToZip($zipArchive, $sourcePath, $projectRoot);
        continue;
    }

    $zipArchive->close();
    @unlink($zipFilePath);
    fwrite(STDERR, sprintf('Configured path "%s" does not exist.', $includePath) . "\n");
    exit(1);
}

removeSkippedEntries($zipArchive);

if (!$zipArchive->close()) {
    fwrite(STDERR, sprintf('Failed to finalize archive "%s".', $zipFileName) . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf("Created %s\n", $zipFileName));

function addDirectoryToZip(ZipArchive $zipArchive, string $directoryPath, string $projectRoot): void
{
    $relativeDirectoryPath = buildRelativePath($directoryPath, $projectRoot) . '/';
    $zipArchive->addEmptyDir($relativeDirectoryPath);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directoryPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $itemPath = $item->getPathname();
        $relativePath = buildRelativePath($itemPath, $projectRoot);

        if (shouldSkipPath($relativePath)) {
            continue;
        }

        if ($item->isDir()) {
            $zipArchive->addEmptyDir($relativePath . '/');
            continue;
        }

        $zipArchive->addFile($itemPath, $relativePath);
    }
}

function addFileToZip(ZipArchive $zipArchive, string $filePath, string $projectRoot): void
{
    $zipArchive->addFile($filePath, buildRelativePath($filePath, $projectRoot));
}

function buildRelativePath(string $path, string $projectRoot): string
{
    return ltrim(str_replace('\\', '/', substr($path, strlen($projectRoot))), '/');
}

function shouldSkipPath(string $relativePath): bool
{
    foreach (explode('/', $relativePath) as $pathSegment) {
        if (str_starts_with($pathSegment, '.git')) {
            return true;
        }
    }

    return false;
}

function removeSkippedEntries(ZipArchive $zipArchive): void
{
    $entriesToRemove = [];

    for ($index = 0; $index < $zipArchive->numFiles; $index++) {
        $entryName = $zipArchive->getNameIndex($index);
        if ($entryName === false || !shouldSkipPath(rtrim($entryName, '/'))) {
            continue;
        }

        $entriesToRemove[] = $entryName;
    }

    foreach ($entriesToRemove as $entryName) {
        $zipArchive->deleteName($entryName);
    }
}
