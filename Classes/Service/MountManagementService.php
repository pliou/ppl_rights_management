<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Service;

use Ppl\PplRightsManagement\Domain\Repository\MountManagementRepository;

class MountManagementService extends AbstractRightsManagementService
{
    public function __construct(MountManagementRepository $repository)
    {
        parent::__construct($repository);
    }
}
