<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Service;

use Ppl\PplRightsManagement\Domain\Repository\GroupManagementRepository;

class GroupManagementService extends AbstractRightsManagementService
{
    public function __construct(GroupManagementRepository $repository)
    {
        parent::__construct($repository);
    }
}
