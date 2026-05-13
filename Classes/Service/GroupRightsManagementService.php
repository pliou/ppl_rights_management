<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Service;

use Ppl\PplRightsManagement\Domain\Repository\GroupRightsManagementRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class GroupRightsManagementService extends AbstractRightsManagementService
{
    public function __construct(GroupRightsManagementRepository $repository)
    {
        parent::__construct($repository);
    }

    public function getViewData(): array
    {
        $data = parent::getViewData();
        $data['selectedGroups'] = $this->getSelectedGroups($data['groups']);
        $data['groups'] = $this->markSelectedGroups($data['groups'], $data['selectedGroups']);
        $groupMap = $this->mapGroupsByUid($data['groups']);
        $accessService = GeneralUtility::makeInstance(RightsManagementAccessService::class);
        $data['pageTypeRows'] = $this->buildPageTypeRows($data['availablePageTypes'], $data['selectedGroups'], $groupMap, $accessService);
        $data['tableRows'] = $this->buildTableRows($data['availableTables'], $data['selectedGroups'], $groupMap, $accessService);

        return $data;
    }

    private function buildPageTypeRows(array $pageTypes, array $selectedGroups, array $groupMap, RightsManagementAccessService $accessService): array
    {
        $rows = [];
        foreach ($pageTypes as $pageType) {
            $canAssign = (bool)($pageType['assignable'] ?? $accessService->canViewPageType($pageType['id'] ?? ''));
            $cells = [];
            foreach ($selectedGroups as $group) {
                $inheritedGroups = $this->getInheritedGroups($group, $groupMap);
                $inheritedFrom = $this->filterGroupsByPageType($inheritedGroups, (string)$pageType['id']);
                $cells[] = [
                    'groupUid' => $group['uid'],
                    'assignable' => (bool)($group['editable'] ?? $group['assignable'] ?? false) && $canAssign,
                    'ownChecked' => in_array((int)$pageType['id'], $group['pageTypeIds'], true)
                        || in_array((string)$pageType['id'], $group['pageTypeIds'], true),
                    'inheritedChecked' => $inheritedFrom !== [],
                    'inheritedFrom' => $this->formatInheritedFrom($inheritedFrom),
                ];
            }
            $rows[] = $pageType + ['cells' => $cells];
        }

        return $rows;
    }

    private function buildTableRows(array $tables, array $selectedGroups, array $groupMap, RightsManagementAccessService $accessService): array
    {
        $rows = [];
        foreach ($tables as $table) {
            $canAssignRead = (bool)($table['canAssignRead'] ?? $accessService->canReadTable((string)($table['id'] ?? '')));
            $canAssignWrite = (bool)($table['canAssignWrite'] ?? $accessService->canWriteTable((string)($table['id'] ?? '')));
            $cells = [];
            foreach ($selectedGroups as $group) {
                $ownMode = $this->resolveTableMode($table['id'], $group);
                $inheritedGroups = $this->getInheritedGroups($group, $groupMap);
                $inheritedMode = $this->resolveInheritedTableMode($table['id'], $inheritedGroups);
                $cells[] = [
                    'groupUid' => $group['uid'],
                    'assignable' => (bool)($group['editable'] ?? $group['assignable'] ?? false) && $canAssignRead,
                    'ownNone' => $ownMode === 'none',
                    'ownRead' => $ownMode === 'read',
                    'ownWrite' => $ownMode === 'write',
                    'inheritedNone' => $inheritedMode['mode'] === 'none',
                    'inheritedRead' => $inheritedMode['mode'] === 'read',
                    'inheritedWrite' => $inheritedMode['mode'] === 'write',
                    'inheritedFrom' => $this->formatInheritedFrom($inheritedMode['groups']),
                    'canAssignRead' => $canAssignRead,
                    'canAssignWrite' => $canAssignWrite,
                ];
            }
            $rows[] = $table + [
                'cells' => $cells,
                'canAssignRead' => $canAssignRead,
                'canAssignWrite' => $canAssignWrite,
            ];
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

    private function filterGroupsByPageType(array $groups, string $pageTypeId): array
    {
        return array_values(array_filter(
            $groups,
            static fn(array $group): bool => in_array((int)$pageTypeId, $group['pageTypeIds'], true)
                || in_array($pageTypeId, $group['pageTypeIds'], true)
        ));
    }

    private function resolveTableMode(string $tableName, array $group): string
    {
        if (in_array($tableName, $group['tablesModify'], true)) {
            return 'write';
        }
        if (in_array($tableName, $group['tablesSelect'], true)) {
            return 'read';
        }

        return 'none';
    }

    private function resolveInheritedTableMode(string $tableName, array $groups): array
    {
        $mode = 'none';
        $matchingGroups = [];
        foreach ($groups as $group) {
            $groupMode = $this->resolveTableMode($tableName, $group);
            if ($groupMode === 'none') {
                continue;
            }
            $matchingGroups[] = $group;
            if ($groupMode === 'write') {
                $mode = 'write';
                continue;
            }
            if ($mode === 'none') {
                $mode = 'read';
            }
        }

        return ['mode' => $mode, 'groups' => $matchingGroups];
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
