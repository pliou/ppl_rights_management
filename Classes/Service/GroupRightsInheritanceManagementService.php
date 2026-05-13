<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Service;

use Ppl\PplRightsManagement\Domain\Repository\GroupRightsInheritanceManagementRepository;

class GroupRightsInheritanceManagementService extends AbstractRightsManagementService
{
    public function __construct(GroupRightsInheritanceManagementRepository $repository)
    {
        parent::__construct($repository);
    }

    public function getViewData(): array
    {
        $data = parent::getViewData();
        $selectedGroup = $this->getSelectedGroup($data['groups']);
        $data['selectedGroup'] = $selectedGroup;
        $data['inheritedGroupIds'] = $selectedGroup['subgroupIds'] ?? [];

        return $data;
    }

    private function getSelectedGroup(array $groups): array
    {
        $requestedUid = $this->getRequestedGroupUid();
        foreach ($groups as $group) {
            if ($requestedUid > 0 && (int)$group['uid'] === $requestedUid && (bool)($group['editable'] ?? $group['assignable'] ?? false)) {
                return $group;
            }
        }
        foreach ($groups as $group) {
            if ((bool)($group['editable'] ?? $group['assignable'] ?? false)) {
                return $group;
            }
        }

        return $groups[0] ?? [];
    }

    private function getRequestedGroupUid(): int
    {
        if (!isset($GLOBALS['TYPO3_REQUEST'])) {
            return 0;
        }

        return (int)($GLOBALS['TYPO3_REQUEST']->getQueryParams()['group'] ?? 0);
    }
}
