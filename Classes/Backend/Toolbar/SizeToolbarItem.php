<?php

declare(strict_types = 1);

namespace T3\Size\Backend\Toolbar;

use Psr\Http\Message\ServerRequestInterface;
use T3\Size\Service\SizeOverviewProvider;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class SizeToolbarItem implements ToolbarItemInterface, RequestAwareToolbarItemInterface
{
    private ServerRequestInterface $request;
    /**
     * @var array<string, mixed>|null
     */
    private ?array $overview = null;

    public function __construct(
        private readonly ModuleProvider $moduleProvider,
        private readonly BackendViewFactory $backendViewFactory,
        private readonly SizeOverviewProvider $sizeOverviewProvider,
        private readonly UriBuilder $uriBuilder,
    ) {
    }

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function checkAccess(): bool
    {
        return $this->moduleProvider->accessGranted('size_storage_statistics', $this->getBackendUser());
    }

    public function getItem(): string
    {
        $view = $this->backendViewFactory->create($this->request, ['t3/size']);
        $view->assignMultiple($this->getOverview());

        return $view->render('ToolbarItems/SizeToolbarItem');

        //        $icon = $this->iconFactory->getIcon(
        //            'actions-info',
        //            IconSize::SMALL
        //        )->render();
        //
        //        return '
        //            <button
        //                type="button"
        //                class="toolbar-item-link dropdown-toggle"
        //                title="Projektinfo"
        //                aria-label="Projektinfo"
        //            >
        //                ' . $icon . '
        //            </button>
        //        ';
    }

    public function hasDropDown(): bool
    {
        return true;
    }

    public function getDropDown(): string
    {
        $view = $this->backendViewFactory->create($this->request, ['t3/size']);
        $view->assignMultiple([
            ...$this->getOverview(),
            'storageStatisticsModuleUrl' => (string)$this->uriBuilder->buildUriFromRoute('size_storage_statistics'),
        ]);

        return $view->render('ToolbarItems/SizeToolbarItemDropDown');
    }

    /**
     * @return array<string, string>
     */
    public function getAdditionalAttributes(): array
    {
        return [
            //            'class' => 'toolbar-item-size',
        ];
    }

    public function getIndex(): int
    {
        return 50;
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * @return array<string, mixed>
     */
    private function getOverview(): array
    {
        if (null === $this->overview) {
            $this->overview = $this->sizeOverviewProvider->getOverview();
        }

        return $this->overview;
    }
}
