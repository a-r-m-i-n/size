<?php

declare(strict_types = 1);

namespace T3\Size\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use T3\Size\Localization\BackendLocalizationHelper;
use T3\Size\Service\SizeOverviewProvider;
use T3\Size\Service\SizeOverviewRefreshService;
use T3\Size\Service\SizeOverviewSnapshotStorage;
use T3\Size\Service\StorageUsageNotificationRegistry;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

#[AsController]
final readonly class StorageStatisticsController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private SizeOverviewProvider $sizeOverviewProvider,
        private BackendLocalizationHelper $backendLocalizationHelper,
        private SizeOverviewRefreshService $refreshService,
        private SizeOverviewSnapshotStorage $snapshotStorage,
        private StorageUsageNotificationRegistry $notificationRegistry,
        private UriBuilder $uriBuilder,
        private FlashMessageService $flashMessageService,
        private FormProtectionFactory $formProtectionFactory,
    ) {
    }

    public function overviewAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle($this->translate('module.storageStatistics.title'));
        $context = $this->sizeOverviewProvider->getOverviewContext();

        $moduleTemplate->assignMultiple([
            ...$context['overview'],
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

    private function validateProtectedActionRequest(
        ServerRequestInterface $request,
        string $tokenAction,
        string $adminMessageKey
    ): ?ResponseInterface {
        if (!$this->sizeOverviewProvider->isAdminUser()) {
            $this->enqueueFlashMessage(
                $this->translate($adminMessageKey),
                ContextualFeedbackSeverity::WARNING
            );

            return $this->redirectToOverview();
        }

        $parsedBody = $request->getParsedBody();
        $formToken = is_array($parsedBody) ? (string)($parsedBody['formToken'] ?? '') : '';
        if (!$this->formProtectionFactory->createFromRequest($request)->validateToken(
            $formToken,
            'size/storage-statistics',
            $tokenAction
        )) {
            return $this->redirectToOverview();
        }

        return null;
    }

    private function redirectToOverview(): ResponseInterface
    {
        return new RedirectResponse(
            (string)$this->uriBuilder->buildUriFromRoute('size_storage_statistics')
        );
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
}
