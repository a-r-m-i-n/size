<?php

declare(strict_types = 1);

namespace T3\Size\Localization;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

final readonly class BackendLocalizationHelper
{
    public function __construct(
        private LanguageServiceFactory $languageServiceFactory,
    ) {
    }

    public function translate(string $key): string
    {
        return $this->getLanguageService()
            ->sL('LLL:EXT:size/Resources/Private/Language/locallang.xlf:' . $key) ?: $key;
    }

    public function resolveLabel(string $label): string
    {
        $languageService = $this->getLanguageService();

        if (str_starts_with($label, 'LLL:')) {
            return $languageService->sL($label) ?: $label;
        }

        if (1 === preg_match('/^([a-z0-9_.-]+):([a-z0-9_.-]+)$/i', $label, $matches)) {
            return (string)($languageService->translate($matches[2], $matches[1]) ?? $label);
        }

        return $label;
    }

    private function getLanguageService(): LanguageService
    {
        return $this->languageServiceFactory->createFromUserPreferences($this->getBackendUser());
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }
}
