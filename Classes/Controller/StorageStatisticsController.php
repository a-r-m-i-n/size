<?php

declare(strict_types = 1);

namespace T3\Size\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use T3\Size\Localization\BackendLocalizationHelper;
use T3\Size\Service\SizeOverviewProvider;
use T3\Size\Service\SizeOverviewRefreshService;
use T3\Size\Service\SizeOverviewSnapshotStorage;
use T3\Size\Service\StorageStatisticsHistoryService;
use T3\Size\Service\StorageUsageNotificationRegistry;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Breadcrumb\BreadcrumbContext;
use TYPO3\CMS\Backend\Dto\Breadcrumb\BreadcrumbNode;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownRadio;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsController]
final readonly class StorageStatisticsController
{
    private const OVERVIEW_ROUTE = 'size_storage_statistics';
    private const HISTORY_ROUTE = 'size_storage_statistics_history';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private SizeOverviewProvider $sizeOverviewProvider,
        private BackendLocalizationHelper $backendLocalizationHelper,
        private SizeOverviewRefreshService $refreshService,
        private SizeOverviewSnapshotStorage $snapshotStorage,
        private StorageStatisticsHistoryService $historyService,
        private StorageUsageNotificationRegistry $notificationRegistry,
        private UriBuilder $uriBuilder,
        private FlashMessageService $flashMessageService,
        private FormProtectionFactory $formProtectionFactory,
    ) {
    }

    public function overviewAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $this->configureModuleHeader($moduleTemplate, $request, self::OVERVIEW_ROUTE, 'module.storageStatistics.title');
        $context = $this->sizeOverviewProvider->getOverviewContext();
        $overview = $this->enrichOverviewForBackendModule($context['overview']);

        $moduleTemplate->assignMultiple([
            ...$overview,
            ...$context,
            'refreshFormId' => 'size-overview-refresh-form',
            'refreshActionUrl' => (string)$this->uriBuilder->buildUriFromRoute('size_storage_statistics.refresh'),
            'refreshFormToken' => $this->formProtectionFactory->createFromRequest($request)
                ->generateToken('size/storage-statistics', 'refresh'),
            'resetFormId' => 'size-overview-reset-form',
            'resetActionUrl' => (string)$this->uriBuilder->buildUriFromRoute('size_storage_statistics.reset'),
            'resetFormToken' => $this->formProtectionFactory->createFromRequest($request)
                ->generateToken('size/storage-statistics', 'reset'),
            'resetModalTitle' => $this->translate('module.storageStatistics.resetModalTitle'),
            'resetModalMessage' => $this->translate('module.storageStatistics.resetModalMessage'),
            'resetModalConfirmLabel' => $this->translate('module.storageStatistics.resetModalConfirmLabel'),
            'resetModalCancelLabel' => $this->translate('module.storageStatistics.resetModalCancelLabel'),
        ]);

        return $moduleTemplate->renderResponse('Modules/StorageStatistics');
    }

    public function historyAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->historyService->isHistoryEnabled()) {
            return $this->redirectToOverview();
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $this->configureModuleHeader($moduleTemplate, $request, self::HISTORY_ROUTE, 'module.storageStatistics.history.title');
        $context = $this->sizeOverviewProvider->getOverviewContext();
        $overview = $context['overview'];
        $queryParams = $request->getQueryParams();
        $selectedMetric = is_string($queryParams['metric'] ?? null) ? $queryParams['metric'] : 'total';
        $selectedPeriod = is_string($queryParams['period'] ?? null) ? $queryParams['period'] : 'day';
        $historyView = $this->historyService->getHistoryModuleData($overview, $selectedMetric, $selectedPeriod);

        $moduleTemplate->assignMultiple([
            ...$context,
            ...$historyView,
            'resetHistoryFormId' => 'size-history-reset-form',
            'resetHistoryActionUrl' => (string)$this->uriBuilder->buildUriFromRoute('size_storage_statistics_history.reset'),
            'resetHistoryFormToken' => $this->formProtectionFactory->createFromRequest($request)
                ->generateToken('size/storage-statistics', 'resetHistory'),
            'resetHistoryModalTitle' => $this->translate('module.storageStatistics.history.resetModalTitle'),
            'resetHistoryModalMessage' => $this->translate('module.storageStatistics.history.resetModalMessage'),
            'resetHistoryModalConfirmLabel' => $this->translate('module.storageStatistics.history.resetModalConfirmLabel'),
            'resetHistoryModalCancelLabel' => $this->translate('module.storageStatistics.history.resetModalCancelLabel'),
        ]);

        return $moduleTemplate->renderResponse('Modules/StorageStatisticsHistory');
    }

    private function configureModuleHeader(
        ModuleTemplate $moduleTemplate,
        ServerRequestInterface $request,
        string $activeRoute,
        string $titleKey
    ): void {
        $activeLabel = $this->translate($titleKey);
        $historyEnabled = $this->historyService->isHistoryEnabled();
        $moduleTemplate->setTitle($activeLabel);

        $docHeader = $moduleTemplate->getDocHeaderComponent();
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        if ($historyEnabled) {
            $navigationButton = $buttonBar->makeDropDownButton()
                ->setLabel($activeLabel)
                ->setTitle($activeLabel)
                ->setShowLabelText(true)
                ->setIcon(null);

            foreach ([
                self::OVERVIEW_ROUTE => 'module.storageStatistics.title',
                self::HISTORY_ROUTE => 'module.storageStatistics.history.title',
            ] as $route => $labelKey) {
                $label = $this->translate($labelKey);
                $navigationButton->addItem(
                    GeneralUtility::makeInstance(DropDownRadio::class)
                        ->setHref((string)$this->uriBuilder->buildUriFromRoute($route))
                        ->setLabel($label)
                        ->setTitle($label)
                        ->setActive($route === $activeRoute)
                );
            }

            $buttonBar->addButton(
                $navigationButton,
                ButtonBar::BUTTON_POSITION_LEFT,
                10
            );
        }

        if ($this->isTypo3V14OrNewer()) {
            $dashboardLabel = $this->translate('module.storageStatistics.breadcrumb.dashboard');
            $breadcrumbNodes = [
                new BreadcrumbNode(
                    identifier: self::OVERVIEW_ROUTE,
                    label: $dashboardLabel,
                    url: (string)$this->uriBuilder->buildUriFromRoute(self::OVERVIEW_ROUTE)
                ),
            ];
            if (self::HISTORY_ROUTE === $activeRoute) {
                $breadcrumbNodes[] = new BreadcrumbNode(
                    identifier: self::HISTORY_ROUTE,
                    label: $this->translate('module.storageStatistics.history.title'),
                    url: (string)$request->getUri()
                );
            }
            $docHeader->setBreadcrumbContext(new BreadcrumbContext(null, $breadcrumbNodes));
        }

        $shortcutArguments = $this->buildShortcutArguments($request);
        if ($this->isTypo3V14OrNewer()) {
            $docHeader->setShortcutContext($activeRoute, $activeLabel, $shortcutArguments);
        } else {
            $shortcutButton = $buttonBar->makeShortcutButton()
                ->setRouteIdentifier($activeRoute)
                ->setDisplayName($activeLabel)
                ->setArguments($shortcutArguments);
            $buttonBar->addButton($shortcutButton);
            $buttonBar->addButton(
                $buttonBar->makeLinkButton()
                    ->setHref((string)$request->getUri())
                    ->setTitle($this->translateCoreLabel('labels.reload', 'Reload'))
                    ->setIcon(GeneralUtility::makeInstance(IconFactory::class)->getIcon('actions-refresh', IconSize::SMALL)),
                ButtonBar::BUTTON_POSITION_RIGHT,
                90
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildShortcutArguments(ServerRequestInterface $request): array
    {
        return $request->getQueryParams();
    }

    public function refreshAction(ServerRequestInterface $request): ResponseInterface
    {
        $validationResponse = $this->validateProtectedActionRequest(
            $request,
            'refresh',
            'module.storageStatistics.refreshRequiresAdmin'
        );
        if ($validationResponse instanceof ResponseInterface) {
            return $validationResponse;
        }

        $result = $this->refreshService->refresh();
        if ($result->wasLocked()) {
            $this->enqueueFlashMessage(
                $this->translate('module.storageStatistics.refreshLocked'),
                ContextualFeedbackSeverity::WARNING
            );

            return $this->redirectToOverview();
        }

        $this->enqueueFlashMessage(
            sprintf(
                $this->translate('module.storageStatistics.refreshSuccess'),
                $result->durationMs ?? 0
            ),
            ContextualFeedbackSeverity::OK
        );

        return $this->redirectToOverview();
    }

    public function resetAction(ServerRequestInterface $request): ResponseInterface
    {
        $validationResponse = $this->validateProtectedActionRequest(
            $request,
            'reset',
            'module.storageStatistics.resetRequiresAdmin'
        );
        if ($validationResponse instanceof ResponseInterface) {
            return $validationResponse;
        }

        $this->snapshotStorage->removeSnapshot();
        $this->notificationRegistry->reset();

        $this->enqueueFlashMessage(
            $this->translate('module.storageStatistics.resetSuccess'),
            ContextualFeedbackSeverity::OK
        );

        return $this->redirectToOverview();
    }

    public function resetHistoryAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->historyService->isHistoryEnabled()) {
            return $this->redirectToOverview();
        }

        $validationResponse = $this->validateProtectedActionRequest(
            $request,
            'resetHistory',
            'module.storageStatistics.history.resetRequiresAdmin',
            'size_storage_statistics_history'
        );
        if ($validationResponse instanceof ResponseInterface) {
            return $validationResponse;
        }

        $this->historyService->resetHistory();

        $this->enqueueFlashMessage(
            $this->translate('module.storageStatistics.history.resetSuccess'),
            ContextualFeedbackSeverity::OK
        );

        return $this->redirectToRoute('size_storage_statistics_history');
    }

    private function validateProtectedActionRequest(
        ServerRequestInterface $request,
        string $tokenAction,
        string $adminMessageKey,
        string $redirectRoute = 'size_storage_statistics'
    ): ?ResponseInterface {
        if (!$this->sizeOverviewProvider->isAdminUser()) {
            $this->enqueueFlashMessage(
                $this->translate($adminMessageKey),
                ContextualFeedbackSeverity::WARNING
            );

            return $this->redirectToRoute($redirectRoute);
        }

        $parsedBody = $request->getParsedBody();
        $formToken = is_array($parsedBody) ? (string)($parsedBody['formToken'] ?? '') : '';
        if (!$this->formProtectionFactory->createFromRequest($request)->validateToken(
            $formToken,
            'size/storage-statistics',
            $tokenAction
        )) {
            return $this->redirectToRoute($redirectRoute);
        }

        return null;
    }

    private function redirectToOverview(): ResponseInterface
    {
        return $this->redirectToRoute(self::OVERVIEW_ROUTE);
    }

    private function redirectToRoute(string $route): ResponseInterface
    {
        return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute($route));
    }

    private function enqueueFlashMessage(string $message, ContextualFeedbackSeverity $severity): void
    {
        $this->flashMessageService->getMessageQueueByIdentifier()->enqueue(
            new FlashMessage($message, '', $severity, true)
        );
    }

    private function translate(string $key): string
    {
        return $this->backendLocalizationHelper->translate($key);
    }

    private function translateCoreLabel(string $key, string $fallback): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if ($languageService instanceof LanguageService) {
            return $languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:' . $key) ?: $fallback;
        }

        return $fallback;
    }

    private function isTypo3V14OrNewer(): bool
    {
        return class_exists('TYPO3\\CMS\\Backend\\Template\\Components\\ComponentFactory');
    }

    /**
     * @param array<string, mixed> $overview
     *
     * @return array<string, mixed>
     */
    private function enrichOverviewForBackendModule(array $overview): array
    {
        $largestFalFiles = $overview['largestFalFiles']['items'] ?? null;
        if (!is_array($largestFalFiles)) {
            return $overview;
        }

        $overview['largestFalFiles']['items'] = array_map(function (mixed $item): mixed {
            if (!is_array($item)) {
                return $item;
            }

            $item['link'] = $this->buildSysFileEditLink((int)($item['sysFileUid'] ?? 0));

            return $item;
        }, $largestFalFiles);

        return $overview;
    }

    private function buildSysFileEditLink(int $sysFileUid): ?string
    {
        if ($sysFileUid <= 0) {
            return null;
        }

        try {
            return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [
                    'sys_file' => [
                        $sysFileUid => 'edit',
                    ],
                ],
            ]);
        } catch (\Throwable) {
            return null;
        }
    }
}
