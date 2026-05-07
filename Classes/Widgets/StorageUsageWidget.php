<?php

declare(strict_types=1);

namespace T3\Size\Widgets;

use Psr\Http\Message\ServerRequestInterface;
use T3\Size\Service\SizeOverviewProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\RequestAwareWidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

final class StorageUsageWidget implements WidgetInterface, RequestAwareWidgetInterface, AdditionalCssInterface
{
    private ServerRequestInterface $request;

    public function __construct(
        private readonly WidgetConfigurationInterface $configuration,
        private readonly BackendViewFactory $backendViewFactory,
        private readonly SizeOverviewProvider $sizeOverviewProvider,
        private readonly UriBuilder $uriBuilder,
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function renderWidgetContent(): string
    {
        $view = $this->backendViewFactory->create($this->request, ['t3/size']);
        $overview = $this->sizeOverviewProvider->getOverview();
        $view->assignMultiple([
            'chart' => $overview['chart'],
            'total' => $overview['total'],
            'storageStatisticsModuleUrl' => (string)$this->uriBuilder->buildUriFromRoute('size_storage_statistics'),
            'configuration' => $this->configuration,
        ]);

        return $view->render('Widgets/StorageUsageWidget');
    }

    public function getOptions(): array
    {
        return [];
    }

    public function getCssFiles(): array
    {
        return [
            'EXT:size/Resources/Public/Css/StorageUsageWidget.css',
        ];
    }
}
