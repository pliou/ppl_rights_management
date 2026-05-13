<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Service;

use Ppl\PplRightsManagement\Domain\Repository\GroupBackendUserManagementRepository;

class GroupBackendUserManagementService extends AbstractRightsManagementService
{
    public function __construct(GroupBackendUserManagementRepository $repository)
    {
        parent::__construct($repository);
    }
}
