<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Service;

use Ppl\PplRightsManagement\Domain\Repository\OverviewManagementRepository;

class OverviewManagementService extends AbstractRightsManagementService
{
    private const MAX_SELECTED_SUBJECTS = 8;
    private const PAGE_LABEL_DISPLAY_LIMIT = 25;

    public function __construct(OverviewManagementRepository $repository)
    {
        parent::__construct($repository);
    }

    public function getViewData(): array
    {
        $data = parent::getViewData();
        $groupMap = $this->mapRowsByUid($data['groups']);
        $subject = $this->getOverviewSubject();
        $selectedGroups = $this->getSelectedGroupsForOverview($data['groups']);
        $selectedUsers = $this->getSelectedUsers($data['users']);

        $data['overviewSubject'] = $subject;
        $data['isGroupOverview'] = $subject === 'groups';
        $data['isUserOverview'] = $subject === 'users';
        $data['groups'] = $this->markRowsSelected($data['groups'], $selectedGroups);
        $data['users'] = $this->markRowsSelected($data['users'], $selectedUsers);
        $data['selectedGroups'] = $selectedGroups;
        $data['selectedUsers'] = $selectedUsers;
        $data['selectedSubjects'] = $subject === 'users' ? $selectedUsers : $selectedGroups;
        $data['overviewSections'] = $subject === 'users'
            ? $this->buildUserSections($data, $selectedUsers, $groupMap)
            : $this->buildGroupSections($data, $selectedGroups, $groupMap);
        $data['overviewColspan'] = 1 + (count($data['selectedSubjects']) * 2);
        $data['groupSelectionQuery'] = $this->buildListQuery('groups', $selectedGroups);
        $data['userSelectionQuery'] = $this->buildListQuery('users', $selectedUsers);
        $data['firstSelectedGroupQuery'] = $this->buildSingleQuery('group', $selectedGroups);
        $data['firstSelectedUserQuery'] = $this->buildSingleQuery('user', $selectedUsers);

        return $data;
    }

    private function buildGroupSections(array $data, array $selectedGroups, array $groupMap): array
    {
        $sections = [
            [
                'label' => 'Rechte: Seitentypen',
                'rows' => $this->buildGroupPageTypeRows($data['availablePageTypes'], $selectedGroups, $groupMap),
            ],
            [
                'label' => 'Rechte: Tabellen',
                'rows' => $this->buildGroupTableRows($data['availableTables'], $selectedGroups, $groupMap),
            ],
            [
                'label' => 'Module',
                'rows' => $this->buildGroupModuleRows($data['availableModules'], $selectedGroups, $groupMap),
            ],
            [
                'label' => 'Mounts: Datenbank',
                'rows' => $this->buildGroupMountRows($data['availablePages'], $selectedGroups, $groupMap, 'dbMountIds'),
            ],
        ];

        if ($data['hasFileMountFeature'] ?? false) {
            $sections[] = [
                'label' => 'Mounts: Dateien',
                'rows' => $this->buildGroupMountRows($data['availableFileMounts'], $selectedGroups, $groupMap, 'fileMountIds'),
            ];
        }

        return $sections;
    }

    private function buildUserSections(array $data, array $selectedUsers, array $groupMap): array
    {
        $contexts = $this->buildUserContexts($selectedUsers, $groupMap);

        $sections = [
            [
                'label' => 'Rechte: Seitentypen',
                'rows' => $this->buildUserPageTypeRows($data['availablePageTypes'], $selectedUsers, $contexts),
            ],
            [
                'label' => 'Rechte: Tabellen',
                'rows' => $this->buildUserTableRows($data['availableTables'], $selectedUsers, $contexts),
            ],
            [
                'label' => 'Module',
                'rows' => $this->buildUserModuleRows($data['availableModules'], $selectedUsers, $contexts),
            ],
            [
                'label' => 'Mounts: Datenbank',
                'rows' => $this->buildUserMountRows($data['availablePages'], $selectedUsers, $contexts, 'dbMountIds'),
            ],
        ];

        if ($data['hasFileMountFeature'] ?? false) {
            $sections[] = [
                'label' => 'Mounts: Dateien',
                'rows' => $this->buildUserMountRows($data['availableFileMounts'], $selectedUsers, $contexts, 'fileMountIds'),
            ];
        }

        return $sections;
    }

    private function buildGroupPageTypeRows(array $pageTypes, array $selectedGroups, array $groupMap): array
    {
        $rows = [];
        foreach ($pageTypes as $pageType) {
            $cells = [];
            foreach ($selectedGroups as $group) {
                $inheritedFrom = $this->filterGroupsByValue($this->getInheritedGroups($group, $groupMap), 'pageTypeIds', $pageType['id']);
                $cells[] = $this->buildBooleanCell(
                    $this->containsValue($group['pageTypeIds'], $pageType['id']),
                    $inheritedFrom,
                    'direkt gesetzt'
                );
            }
            $rows[] = $this->buildRow((string)$pageType['label'], '[' . $pageType['id'] . ']', $cells);
        }

        return $rows;
    }

    private function buildGroupTableRows(array $tables, array $selectedGroups, array $groupMap): array
    {
        $rows = [];
        foreach ($tables as $table) {
            $cells = [];
            foreach ($selectedGroups as $group) {
                $inheritedMode = $this->resolveHighestTableMode($table['id'], $this->getInheritedGroups($group, $groupMap));
                $cells[] = $this->buildModeCell(
                    $this->resolveTableMode($table['id'], $group),
                    $inheritedMode['mode'],
                    $inheritedMode['groups']
                );
            }
            $rows[] = $this->buildRow((string)$table['label'], '[' . $table['id'] . ']', $cells);
        }

        return $rows;
    }

    private function buildGroupModuleRows(array $modules, array $selectedGroups, array $groupMap): array
    {
        $rows = [];
        foreach ($modules as $module) {
            $cells = [];
            foreach ($selectedGroups as $group) {
                $inheritedFrom = $this->filterGroupsByValue($this->getInheritedGroups($group, $groupMap), 'moduleIds', $module['id']);
                $cells[] = $this->buildBooleanCell(
                    $this->containsValue($group['moduleIds'], $module['id']),
                    $inheritedFrom,
                    'direkt gesetzt'
                );
            }
            $rows[] = $this->buildRow((string)$module['label'], '[' . $module['id'] . ']', $cells);
        }

        return $rows;
    }

    private function buildGroupMountRows(array $mounts, array $selectedGroups, array $groupMap, string $field): array
    {
        $rows = [];
        foreach ($mounts as $mount) {
            $mountId = $mount['uid'];
            $cells = [];
            foreach ($selectedGroups as $group) {
                $inheritedFrom = $this->filterGroupsByValue($this->getInheritedGroups($group, $groupMap), $field, $mountId);
                $cells[] = $this->buildBooleanCell(
                    $this->containsValue($group[$field], $mountId),
                    $inheritedFrom,
                    'direkt gesetzt'
                );
            }
            $rows[] = $this->buildRow(
                (string)$mount['label'],
                '[' . ($mount['meta'] ?: $mountId) . ']',
                $cells,
                $field === 'dbMountIds'
            );
        }

        return $rows;
    }

    private function buildUserPageTypeRows(array $pageTypes, array $selectedUsers, array $contexts): array
    {
        $rows = [];
        foreach ($pageTypes as $pageType) {
            $cells = [];
            foreach ($selectedUsers as $user) {
                $context = $contexts[(int)$user['uid']] ?? ['directGroups' => [], 'inheritedGroups' => []];
                $directGroups = $this->filterGroupsByValue($context['directGroups'], 'pageTypeIds', $pageType['id']);
                $inheritedGroups = $this->filterGroupsByValue($context['inheritedGroups'], 'pageTypeIds', $pageType['id']);
                $cells[] = $this->buildBooleanCell($directGroups !== [], $inheritedGroups, $this->formatSources($directGroups));
            }
            $rows[] = $this->buildRow((string)$pageType['label'], '[' . $pageType['id'] . ']', $cells);
        }

        return $rows;
    }

    private function buildUserTableRows(array $tables, array $selectedUsers, array $contexts): array
    {
        $rows = [];
        foreach ($tables as $table) {
            $cells = [];
            foreach ($selectedUsers as $user) {
                $context = $contexts[(int)$user['uid']] ?? ['directGroups' => [], 'inheritedGroups' => []];
                $directMode = $this->resolveHighestTableMode($table['id'], $context['directGroups']);
                $inheritedMode = $this->resolveHighestTableMode($table['id'], $context['inheritedGroups']);
                $cells[] = $this->buildModeCell(
                    $directMode['mode'],
                    $inheritedMode['mode'],
                    $inheritedMode['groups'],
                    $this->formatSources($directMode['groups'])
                );
            }
            $rows[] = $this->buildRow((string)$table['label'], '[' . $table['id'] . ']', $cells);
        }

        return $rows;
    }

    private function buildUserModuleRows(array $modules, array $selectedUsers, array $contexts): array
    {
        $rows = [];
        foreach ($modules as $module) {
            $cells = [];
            foreach ($selectedUsers as $user) {
                $context = $contexts[(int)$user['uid']] ?? ['directGroups' => [], 'inheritedGroups' => []];
                $directGroups = $this->filterGroupsByValue($context['directGroups'], 'moduleIds', $module['id']);
                $inheritedGroups = $this->filterGroupsByValue($context['inheritedGroups'], 'moduleIds', $module['id']);
                $cells[] = $this->buildBooleanCell($directGroups !== [], $inheritedGroups, $this->formatSources($directGroups));
            }
            $rows[] = $this->buildRow((string)$module['label'], '[' . $module['id'] . ']', $cells);
        }

        return $rows;
    }

    private function buildUserMountRows(array $mounts, array $selectedUsers, array $contexts, string $field): array
    {
        $rows = [];
        foreach ($mounts as $mount) {
            $mountId = $mount['uid'];
            $cells = [];
            foreach ($selectedUsers as $user) {
                $context = $contexts[(int)$user['uid']] ?? ['directGroups' => [], 'inheritedGroups' => []];
                $groupsWithMount = $this->filterGroupsByValue(
                    array_merge($context['directGroups'], $context['inheritedGroups']),
                    $field,
                    $mountId
                );
                $cells[] = $this->buildBooleanCell(
                    $this->containsValue($user[$field], $mountId),
                    $groupsWithMount,
                    'direkt am User'
                );
            }
            $rows[] = $this->buildRow(
                (string)$mount['label'],
                '[' . ($mount['meta'] ?: $mountId) . ']',
                $cells,
                $field === 'dbMountIds'
            );
        }

        return $rows;
    }

    private function buildUserContexts(array $selectedUsers, array $groupMap): array
    {
        $contexts = [];
        foreach ($selectedUsers as $user) {
            $directGroups = [];
            $inheritedGroups = [];
            foreach ($user['groupIds'] as $groupId) {
                if (!isset($groupMap[(int)$groupId])) {
                    continue;
                }
                $group = $groupMap[(int)$groupId];
                $directGroups[(int)$group['uid']] = $group;
                foreach ($this->getInheritedGroups($group, $groupMap) as $inheritedGroup) {
                    $inheritedGroups[(int)$inheritedGroup['uid']] = $inheritedGroup;
                }
            }
            foreach (array_keys($directGroups) as $directGroupId) {
                unset($inheritedGroups[$directGroupId]);
            }
            $contexts[(int)$user['uid']] = [
                'directGroups' => array_values($directGroups),
                'inheritedGroups' => array_values($inheritedGroups),
            ];
        }

        return $contexts;
    }

    private function buildBooleanCell(bool $ownActive, array $inheritedGroups, string $ownDetail = ''): array
    {
        return [
            'ownLabel' => $ownActive ? 'Ja' : '-',
            'ownActive' => $ownActive,
            'ownDetail' => $ownActive ? $ownDetail : '',
            'inheritedLabel' => $inheritedGroups !== [] ? 'Ja' : '-',
            'inheritedActive' => $inheritedGroups !== [],
            'inheritedDetail' => $this->formatSources($inheritedGroups),
        ];
    }

    private function buildModeCell(string $ownMode, string $inheritedMode, array $inheritedGroups, string $ownDetail = ''): array
    {
        return [
            'ownLabel' => $this->formatMode($ownMode),
            'ownActive' => $ownMode !== 'none',
            'ownDetail' => $ownMode !== 'none' ? $ownDetail : '',
            'inheritedLabel' => $this->formatMode($inheritedMode),
            'inheritedActive' => $inheritedMode !== 'none',
            'inheritedDetail' => $this->formatSources($inheritedGroups),
        ];
    }

    private function buildRow(string $label, string $meta, array $cells, bool $truncateLabel = false): array
    {
        return [
            'label' => $truncateLabel ? $this->truncatePageLabel($label) : $label,
            'fullLabel' => $label,
            'meta' => $meta,
            'cells' => $cells,
        ];
    }

    private function truncatePageLabel(string $label): string
    {
        if ($this->stringLength($label) <= self::PAGE_LABEL_DISPLAY_LIMIT) {
            return $label;
        }

        return $this->substring($label, 0, self::PAGE_LABEL_DISPLAY_LIMIT) . '...';
    }

    private function stringLength(string $value): int
    {
        return \function_exists('mb_strlen') ? \mb_strlen($value) : \strlen($value);
    }

    private function substring(string $value, int $start, int $length): string
    {
        return \function_exists('mb_substr') ? \mb_substr($value, $start, $length) : \substr($value, $start, $length);
    }

    private function getOverviewSubject(): string
    {
        if (!isset($GLOBALS['TYPO3_REQUEST'])) {
            return 'groups';
        }

        return ($GLOBALS['TYPO3_REQUEST']->getQueryParams()['subject'] ?? 'groups') === 'users' ? 'users' : 'groups';
    }

    private function getSelectedGroupsForOverview(array $groups): array
    {
        $selectedGroupIds = $this->getSelectedGroupIds();
        if ($selectedGroupIds === []) {
            return array_slice($groups, 0, self::MAX_SELECTED_SUBJECTS);
        }

        return array_slice(array_values(array_filter(
            $groups,
            static fn(array $group): bool => in_array((int)$group['uid'], $selectedGroupIds, true)
        )), 0, self::MAX_SELECTED_SUBJECTS);
    }

    private function getSelectedUsers(array $users): array
    {
        $selectedUserIds = $this->getSelectedUserIds();
        if ($selectedUserIds === []) {
            return array_slice($users, 0, self::MAX_SELECTED_SUBJECTS);
        }

        return array_slice(array_values(array_filter(
            $users,
            static fn(array $user): bool => in_array((int)$user['uid'], $selectedUserIds, true)
        )), 0, self::MAX_SELECTED_SUBJECTS);
    }

    private function getSelectedUserIds(): array
    {
        if (!isset($GLOBALS['TYPO3_REQUEST'])) {
            return [];
        }

        $users = $GLOBALS['TYPO3_REQUEST']->getQueryParams()['users'] ?? [];
        if (!is_array($users)) {
            $users = [$users];
        }

        return array_values(array_filter(array_map('intval', $users)));
    }

    private function markRowsSelected(array $rows, array $selectedRows): array
    {
        $selectedIds = array_map(static fn(array $row): int => (int)$row['uid'], $selectedRows);
        foreach ($rows as $index => $row) {
            $rows[$index]['selected'] = in_array((int)$row['uid'], $selectedIds, true);
        }

        return $rows;
    }

    private function getInheritedGroups(array $group, array $groupMap, array $visited = []): array
    {
        $groups = [];
        $visited[(int)$group['uid']] = true;
        foreach ($group['subgroupIds'] as $subgroupId) {
            $subgroupId = (int)$subgroupId;
            if (isset($visited[$subgroupId]) || !isset($groupMap[$subgroupId])) {
                continue;
            }
            $visited[$subgroupId] = true;
            $groups[] = $groupMap[$subgroupId];
            $groups = array_merge($groups, $this->getInheritedGroups($groupMap[$subgroupId], $groupMap, $visited));
        }

        return $groups;
    }

    private function filterGroupsByValue(array $groups, string $field, string|int $value): array
    {
        return array_values(array_filter(
            $groups,
            fn(array $group): bool => $this->containsValue($group[$field] ?? [], $value)
        ));
    }

    private function containsValue(array $values, string|int $value): bool
    {
        return in_array((int)$value, $values, true) || in_array((string)$value, $values, true);
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

    private function resolveHighestTableMode(string $tableName, array $groups): array
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

    private function formatMode(string $mode): string
    {
        return match ($mode) {
            'write' => 'Schreiben',
            'read' => 'Lesen',
            default => '-',
        };
    }

    private function formatSources(array $groups): string
    {
        if ($groups === []) {
            return '';
        }

        return implode(', ', array_map(
            static fn(array $group): string => $group['title'] . ' [' . $group['uid'] . ']',
            $groups
        ));
    }

    private function mapRowsByUid(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['uid']] = $row;
        }

        return $map;
    }

    private function buildListQuery(string $parameter, array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $parts = [];
        foreach ($rows as $row) {
            $parts[] = rawurlencode($parameter . '[]') . '=' . rawurlencode((string)$row['uid']);
        }

        return '&' . implode('&', $parts);
    }

    private function buildSingleQuery(string $parameter, array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        return '&' . rawurlencode($parameter) . '=' . rawurlencode((string)$rows[0]['uid']);
    }
}
