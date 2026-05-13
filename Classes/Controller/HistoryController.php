<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Controller;

use Ppl\PplRightsManagement\Configuration\AbstractRightsManagementModuleConfiguration;
use Ppl\PplRightsManagement\Configuration\RightsManagementModuleConfiguration as PplRightsManagementModuleConfiguration;
use Ppl\PplRightsManagement\Service\HistoryService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsController]
class HistoryController extends AbstractRightsManagementController
{
    private readonly HistoryService $service;

    public function __construct(
        ?ModuleTemplateFactory $moduleTemplateFactory = null,
        ?AbstractRightsManagementModuleConfiguration $moduleConfiguration = null,
        ?UriBuilder $uriBuilder = null,
        ?PageRenderer $pageRenderer = null,
        ?HistoryService $service = null
    ) {
        $moduleTemplateFactory ??= GeneralUtility::makeInstance(ModuleTemplateFactory::class);
        if (!$moduleConfiguration instanceof AbstractRightsManagementModuleConfiguration) {
            try {
                $moduleConfiguration = GeneralUtility::makeInstance(AbstractRightsManagementModuleConfiguration::class);
            } catch (\Throwable) {
                $moduleConfiguration = GeneralUtility::makeInstance(PplRightsManagementModuleConfiguration::class);
            }
        }
        $uriBuilder ??= GeneralUtility::makeInstance(UriBuilder::class);
        $pageRenderer ??= GeneralUtility::makeInstance(PageRenderer::class);
        $service ??= GeneralUtility::makeInstance(HistoryService::class, GeneralUtility::makeInstance(ConnectionPool::class));
        $this->service = $service;

        parent::__construct($moduleTemplateFactory, $moduleConfiguration, $uriBuilder, $pageRenderer);
    }

    protected function getActiveTabId(ServerRequestInterface $request): string
    {
        return 'history';
    }

    protected function getTemplateName(): string
    {
        return 'RightsManagement/History';
    }

    protected function getViewData(): array
    {
        return $this->service->getViewData();
    }
}
