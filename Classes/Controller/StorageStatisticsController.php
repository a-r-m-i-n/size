<?php

declare(strict_types=1);

namespace T3\Size\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use T3\Size\Service\SizeOverviewProvider;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

#[AsController]
final readonly class StorageStatisticsController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private SizeOverviewProvider $sizeOverviewProvider,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function overviewAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle($this->translate('module.storageStatistics.title'));
        $moduleTemplate->assignMultiple($this->sizeOverviewProvider->getOverview());

        return $moduleTemplate->renderResponse('Modules/StorageStatistics');
    }

    private function translate(string $key): string
    {
        return $this->languageServiceFactory
            ->createFromUserPreferences($GLOBALS['BE_USER'] ?? null)
            ->sL('LLL:EXT:size/Resources/Private/Language/locallang.xlf:' . $key) ?: $key;
    }
}
