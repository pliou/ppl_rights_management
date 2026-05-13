<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Service;

use Ppl\PplRightsManagement\Domain\Repository\ModuleManagementRepository;

class ModuleManagementService extends AbstractRightsManagementService
{
    public function __construct(ModuleManagementRepository $repository)
    {
        parent::__construct($repository);
    }

    public function getViewData(): array
    {
        $data = parent::getViewData();
        $data['selectedGroups'] = $this->getSelectedGroups($data['groups']);
        $data['groups'] = $this->markSelectedGroups($data['groups'], $data['selectedGroups']);
        $data['moduleRows'] = $this->buildModuleRows($data['availableModules'], $data['selectedGroups'], $this->mapGroupsByUid($data['groups']));

        return $data;
    }

    private function buildModuleRows(array $modules, array $selectedGroups, array $groupMap): array
    {
        $rows = [];
        foreach ($modules as $module) {
            $canAssign = (bool)($module['assignable'] ?? false);
            $cells = [];
            foreach ($selectedGroups as $group) {
                $inheritedGroups = $this->getInheritedGroups($group, $groupMap);
                $inheritedFrom = array_values(array_filter(
                    $inheritedGroups,
                    static fn(array $inheritedGroup): bool => in_array($module['id'], $inheritedGroup['moduleIds'], true)
                ));
                $cells[] = [
                    'groupUid' => $group['uid'],
                    'assignable' => (bool)($group['editable'] ?? $group['assignable'] ?? false) && $canAssign,
                    'ownChecked' => in_array($module['id'], $group['moduleIds'], true),
                    'inheritedChecked' => $inheritedFrom !== [],
                    'inheritedFrom' => $this->formatInheritedFrom($inheritedFrom),
                ];
            }
            $rows[] = $module + ['cells' => $cells];
        }

        return $rows;
    }

    private function mapGroupsByUid(array $groups): array
    {
        $map = [];
        foreach ($groups as $group) {
            $map[(int)$group['uid']] = $group;
        }

        return $map;
    }

    private function getInheritedGroups(array $group, array $groupMap, array $visited = []): array
    {
        $groups = [];
        foreach ($group['subgroupIds'] as $subgroupId) {
            $subgroupId = (int)$subgroupId;
            if (isset($visited[$subgroupId], $groupMap[$subgroupId]) || !isset($groupMap[$subgroupId])) {
                continue;
            }
            $visited[$subgroupId] = true;
            $groups[] = $groupMap[$subgroupId];
            $groups = array_merge($groups, $this->getInheritedGroups($groupMap[$subgroupId], $groupMap, $visited));
        }

        return $groups;
    }

    private function formatInheritedFrom(array $groups): string
    {
        if ($groups === []) {
            return '';
        }

        return implode(', ', array_map(
            static fn(array $group): string => $group['title'] . ' [' . $group['uid'] . ']',
            $groups
        ));
    }
}
