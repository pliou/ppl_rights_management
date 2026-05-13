<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Controller;

use Ppl\PplRightsManagement\Configuration\AbstractRightsManagementModuleConfiguration;
use Ppl\PplRightsManagement\Service\ModuleManagementService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;

#[AsController]
class ModuleManagementController extends AbstractRightsManagementController
{
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        AbstractRightsManagementModuleConfiguration $moduleConfiguration,
        UriBuilder $uriBuilder,
        PageRenderer $pageRenderer,
        private readonly ModuleManagementService $service
    ) {
        parent::__construct($moduleTemplateFactory, $moduleConfiguration, $uriBuilder, $pageRenderer);
    }

    protected function getActiveTabId(ServerRequestInterface $request): string
    {
        return 'module-management';
    }

    protected function getTemplateName(): string
    {
        return 'RightsManagement/ModuleManagement';
    }

    protected function getViewData(): array
    {
        return $this->service->getViewData();
    }
}
