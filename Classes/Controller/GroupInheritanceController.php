<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Controller;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;

#[AsController]
class GroupInheritanceController extends GroupRightsInheritanceManagementController
{
    protected function getActiveTabId(ServerRequestInterface $request): string
    {
        return 'group-rights-inheritance-management';
    }
}
