<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Service;

use Ppl\PplRightsManagement\Domain\Repository\BackendUserManagementRepository;

class BackendUserManagementService extends AbstractRightsManagementService
{
    public function __construct(BackendUserManagementRepository $repository)
    {
        parent::__construct($repository);
    }
}
