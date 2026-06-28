<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

class RightsManagementAccessService
{
    private const RIGHTS_MANAGEMENT_MODULES = [
        'ppl_rights_management',
    ];

    public function canUseModule(): bool
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }

        $allowedGroups = $this->getStringListConfiguration('moduleAccessGroupIds');
        if ($allowedGroups === []) {
            // Fail closed: with no module-access groups configured, only admins (handled above)
            // may use the module — mirrors BackendGroupModuleAccessListener, which grants the
            // module to nobody when the list is empty (previously this was an allow-all default).
            return false;
        }

        if ($this->matchesAllowedBackendGroup($allowedGroups, $backendUser)) {
            return true;
        }

        return false;
    }

    public function canSave(): bool
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }

        return $this->delegatedWritesEnabled() && $this->canUseModule();
    }

    public function delegatedWritesEnabled(): bool
    {
        return $this->getBooleanConfiguration('enableDelegatedWrites', false);
    }

    public function protectedGroupUidSet(): array
    {
        $set = [];
        foreach ($this->getStringListConfiguration('protectedGroupUids') as $groupUid) {
            $groupUid = (int)$groupUid;
            if ($groupUid > 0) {
                $set[(string)$groupUid] = true;
            }
        }

        return $set;
    }

    public function canAccessTab(string $tabId): bool
    {
        if ($tabId === 'history') {
            return $this->canSeeHistory();
        }

        return true;
    }

    public function canSeeHistory(): bool
    {
        $backendUser = $this->getBackendUser();

        return $backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin();
    }

    public function filterViewData(array $data): array
    {
        $data = $this->withAssignableFlags($data);
        if (!$this->shouldEnforceUserPermissions()) {
            $data['availableModules'] = $this->filterModules($data['availableModules'] ?? [], false);
            $data['groups'] = $this->markGroupsAssignableByRights($data['groups'] ?? [], $data);
            $writableData = $this->filterViewDataByCurrentPermissions($data);
            $data['users'] = $this->markUsersAssignable($data['users'] ?? [], $writableData['users'] ?? []);
            return $this->withAccessFlags($data);
        }

        return $this->filterViewDataByCurrentPermissions($data);
    }

    public function filterWritableViewData(array $data): array
    {
        return $this->filterViewDataByCurrentPermissions($this->withAssignableFlags($data));
    }

    private function filterViewDataByCurrentPermissions(array $data): array
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->withAccessFlags($this->emptyViewData($data));
        }
        if ($backendUser->isAdmin()) {
            $data['availableModules'] = $this->filterModules($data['availableModules'] ?? [], true);
            $data['groups'] = $this->markGroupsAssignable($data['groups'] ?? []);
            $data['users'] = $this->markUsersAssignable($data['users'] ?? [], $data['users'] ?? []);
            return $this->withAccessFlags($data);
        }

        $data['availablePageTypes'] = array_values(array_filter(
            $data['availablePageTypes'] ?? [],
            static fn(array $pageType): bool => (bool)($pageType['assignable'] ?? false)
        ));
        $data['availableTables'] = array_values(array_filter(
            $data['availableTables'] ?? [],
            static fn(array $table): bool => (bool)($table['assignable'] ?? false)
        ));
        $data['availableModules'] = $this->filterModules($data['availableModules'] ?? [], true);
        $data['availablePages'] = array_values(array_filter(
            $data['availablePages'] ?? [],
            static fn(array $page): bool => (bool)($page['assignable'] ?? false)
        ));
        $data['availableFileMounts'] = array_values(array_filter(
            $data['availableFileMounts'] ?? [],
            static fn(array $mount): bool => (bool)($mount['assignable'] ?? false)
        ));

        [$groups, $visibleGroupIds] = $this->filterGroups($data['groups'] ?? [], $data);
        $data['groups'] = $groups;
        $data['users'] = $this->filterUsers($data['users'] ?? [], $data, $visibleGroupIds);
        $data['groups'] = $this->attachFilteredUsersToGroups($data['groups'], $data['users']);

        return $this->withAccessFlags($data);
    }

    public function canViewTable(string $tableName): bool
    {
        return $this->canReadTable($tableName);
    }

    public function canReadTable(string $tableName): bool
    {
        if ($tableName === '') {
            return false;
        }

        $backendUser = $this->getBackendUser();
        if ($backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin()) {
            return true;
        }

        return $backendUser instanceof BackendUserAuthentication
            && ($backendUser->check('tables_select', $tableName) || $backendUser->check('tables_modify', $tableName));
    }

    public function canWriteTable(string $tableName): bool
    {
        if ($tableName === '') {
            return false;
        }

        $backendUser = $this->getBackendUser();
        if ($backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin()) {
            return true;
        }

        return $backendUser instanceof BackendUserAuthentication
            && $backendUser->check('tables_modify', $tableName);
    }

    public function canViewModule(string $moduleId): bool
    {
        if ($moduleId === '' || $this->isRightsManagementModule($moduleId)) {
            return false;
        }

        $backendUser = $this->getBackendUser();
        if ($backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin()) {
            return true;
        }

        return $backendUser instanceof BackendUserAuthentication
            && $backendUser->check('modules', $moduleId);
    }

    private function canUseBackendModuleRight(string $moduleId): bool
    {
        if ($moduleId === '') {
            return false;
        }

        $backendUser = $this->getBackendUser();
        if ($backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin()) {
            return true;
        }

        return $backendUser instanceof BackendUserAuthentication
            && $backendUser->check('modules', $moduleId);
    }

    public function canViewPageType(string|int $pageType): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin()) {
            return true;
        }

        return $backendUser instanceof BackendUserAuthentication
            && $backendUser->check('pagetypes_select', (string)$pageType);
    }

    public function canViewPage(array $page): bool
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }

        try {
            return $backendUser->doesUserHaveAccess($page, Permission::PAGE_SHOW);
        } catch (\Throwable) {
            return false;
        }
    }

    public function canViewFileMount(int $fileMountUid): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin()) {
            return true;
        }

        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }

        if (isset($this->currentUserFileMountIdSet()[$fileMountUid])) {
            return true;
        }

        foreach ($backendUser->getFileMountRecords() as $fileMountRecord) {
            if ((int)($fileMountRecord['uid'] ?? 0) === $fileMountUid) {
                return true;
            }
        }

        return false;
    }

    private function filterGroups(array $groups, array $data): array
    {
        $visiblePageIds = $this->uidSet($data['availablePages'] ?? []);
        $visibleFileMountIds = $this->uidSet($data['availableFileMounts'] ?? []);
        $rawGroupMap = $this->mapGroupsByUid($groups);
        $assignableGroupIds = $this->assignableGroupIds($rawGroupMap, $visiblePageIds, $visibleFileMountIds);
        $protectedGroupIds = $this->protectedGroupUidSet();
        $filteredGroups = [];
        $visibleGroupIds = [];

        foreach ($groups as $group) {
            $groupUid = (int)$group['uid'];
            if ($groupUid <= 0) {
                continue;
            }
            if (isset($protectedGroupIds[(string)$groupUid])) {
                continue;
            }
            $group['assignable'] = isset($assignableGroupIds[$groupUid]);
            $group['editable'] = true;
            $group = $this->filterGroupValues($group, $visiblePageIds, $visibleFileMountIds);
            $filteredGroups[$groupUid] = $group;
            $visibleGroupIds[$groupUid] = true;
        }

        $visibleGroups = [];
        foreach ($filteredGroups as $groupUid => $group) {
            if (!isset($visibleGroupIds[$groupUid])) {
                continue;
            }
            $group['subgroupIds'] = array_values(array_filter(
                $group['subgroupIds'],
                static fn(string|int $subgroupId): bool => isset($visibleGroupIds[(int)$subgroupId])
            ));
            $group['subgroupCsv'] = implode(',', $group['subgroupIds']);
            $visibleGroups[] = $group;
        }

        return [$visibleGroups, array_keys($visibleGroupIds)];
    }

    private function markGroupsAssignable(array $groups): array
    {
        foreach ($groups as $index => $group) {
            $groups[$index]['assignable'] = true;
            $groups[$index]['editable'] = true;
        }

        return $groups;
    }

    private function markGroupsAssignableByRights(array $groups, array $data): array
    {
        $visiblePageIds = $this->uidSet(array_values(array_filter(
            $data['availablePages'] ?? [],
            fn(array $page): bool => $this->canViewPage($page)
        )));
        $visibleFileMountIds = $this->uidSet(array_values(array_filter(
            $data['availableFileMounts'] ?? [],
            fn(array $mount): bool => $this->canViewFileMount((int)($mount['uid'] ?? 0))
        )));
        $rawGroupMap = $this->mapGroupsByUid($groups);
        $assignableGroupIds = $this->assignableGroupIds($rawGroupMap, $visiblePageIds, $visibleFileMountIds);
        $protectedGroupIds = $this->protectedGroupUidSet();
        foreach ($groups as $index => $group) {
            $groupUid = (int)($group['uid'] ?? 0);
            $groups[$index]['assignable'] = $groupUid > 0
                && !isset($protectedGroupIds[(string)$groupUid])
                && isset($assignableGroupIds[$groupUid]);
            $groups[$index]['editable'] = $groupUid > 0 && !isset($protectedGroupIds[(string)$groupUid]);
        }

        return $groups;
    }

    private function mapGroupsByUid(array $groups): array
    {
        $groupMap = [];
        foreach ($groups as $group) {
            $groupUid = (int)($group['uid'] ?? 0);
            if ($groupUid > 0) {
                $groupMap[$groupUid] = $group;
            }
        }

        return $groupMap;
    }

    private function assignableGroupIds(array $groupMap, array $visiblePageIds, array $visibleFileMountIds): array
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin()) {
            return array_fill_keys(array_keys($groupMap), true);
        }

        $currentUserGroupIds = $this->currentUserGroupIdSet();
        $assignableGroupIds = [];
        foreach ($currentUserGroupIds as $groupUid => $_) {
            if (isset($groupMap[(int)$groupUid])) {
                $assignableGroupIds[(int)$groupUid] = true;
            }
        }
        foreach ($groupMap as $groupUid => $group) {
            if (isset($assignableGroupIds[(int)$groupUid])) {
                continue;
            }
            if ($this->groupRightsFitCurrentUser($group, $groupMap, $visiblePageIds, $visibleFileMountIds, [], $currentUserGroupIds)) {
                $assignableGroupIds[(int)$groupUid] = true;
            }
        }

        return $assignableGroupIds;
    }

    private function groupRightsFitCurrentUser(
        array $group,
        array $groupMap,
        array $visiblePageIds,
        array $visibleFileMountIds,
        array $visited = [],
        array $currentUserGroupIds = []
    ): bool {
        $groupUid = (int)($group['uid'] ?? 0);
        if ($groupUid > 0) {
            if (isset($currentUserGroupIds[$groupUid])) {
                return true;
            }
            if (isset($visited[$groupUid])) {
                // Cycle back-edge: this group is already being validated by the frame that first
                // reached it (its per-permission checks run on that first visit, before recursing
                // into subgroups), so returning true here only terminates the recursion and can
                // never skip a rights check.
                return true;
            }
            $visited[$groupUid] = true;
        }

        foreach ($group['pageTypeIds'] ?? [] as $pageType) {
            if (!$this->canViewPageType($pageType)) {
                return false;
            }
        }
        foreach ($group['tablesSelect'] ?? [] as $tableName) {
            if (!$this->canReadTable((string)$tableName)) {
                return false;
            }
        }
        foreach ($group['tablesModify'] ?? [] as $tableName) {
            if (!$this->canWriteTable((string)$tableName)) {
                return false;
            }
        }
        foreach ($group['moduleIds'] ?? [] as $moduleId) {
            if (!$this->moduleRightFitsCurrentUser((string)$moduleId)) {
                return false;
            }
        }
        foreach ($group['dbMountIds'] ?? [] as $pageId) {
            if (!isset($visiblePageIds[(int)$pageId])) {
                return false;
            }
        }
        foreach ($group['fileMountIds'] ?? [] as $fileMountId) {
            if (!isset($visibleFileMountIds[(int)$fileMountId])) {
                return false;
            }
        }
        foreach ($group['subgroupIds'] ?? [] as $subgroupId) {
            $subgroupUid = (int)$subgroupId;
            if (!isset($groupMap[$subgroupUid])) {
                return false;
            }
            if (!$this->groupRightsFitCurrentUser($groupMap[$subgroupUid], $groupMap, $visiblePageIds, $visibleFileMountIds, $visited, $currentUserGroupIds)) {
                return false;
            }
        }
        if (!$this->listFitsGroupData('non_exclude_fields', $group['fieldPermissions'] ?? [])) {
            return false;
        }
        if (!$this->listFitsGroupData('explicit_allowdeny', $group['explicitAllowDeny'] ?? [])) {
            return false;
        }
        if (!$this->allowedLanguagesFitCurrentUser($group['allowedLanguages'] ?? [])) {
            return false;
        }
        if (!$this->listFitsGroupData('custom_options', $group['customOptions'] ?? [])) {
            return false;
        }
        if (!$this->listFitsGroupData('file_permissions', $group['filePermissions'] ?? [])) {
            return false;
        }
        if (!$this->categoryPermissionsFitCurrentUser($group['categoryPermissions'] ?? [])) {
            return false;
        }
        if (!$this->workspacePermissionsFitCurrentUser((int)($group['workspacePermissions'] ?? 0))) {
            return false;
        }
        if (trim((string)($group['TSconfig'] ?? '')) !== '') {
            return false;
        }

        return true;
    }

    private function currentUserGroupIdSet(): array
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return [];
        }

        $groupIds = [];
        foreach (($backendUser->userGroupsUID ?? []) as $groupUid) {
            $groupUid = (int)$groupUid;
            if ($groupUid > 0) {
                $groupIds[$groupUid] = true;
            }
        }
        foreach (($backendUser->userGroups ?? []) as $group) {
            $groupUid = is_array($group) ? (int)($group['uid'] ?? 0) : (int)$group;
            if ($groupUid > 0) {
                $groupIds[$groupUid] = true;
            }
        }
        foreach (explode(',', (string)($backendUser->user['usergroup'] ?? '')) as $groupUid) {
            $groupUid = (int)trim($groupUid);
            if ($groupUid > 0) {
                $groupIds[$groupUid] = true;
            }
        }

        return $groupIds;
    }

    private function currentUserFileMountIdSet(): array
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return [];
        }

        $fileMountIds = [];
        foreach ([
            $backendUser->groupData['filemounts'] ?? '',
            $backendUser->user['file_mountpoints'] ?? '',
        ] as $fileMountList) {
            foreach (explode(',', (string)$fileMountList) as $fileMountUid) {
                $fileMountUid = (int)trim($fileMountUid);
                if ($fileMountUid > 0) {
                    $fileMountIds[$fileMountUid] = true;
                }
            }
        }
        foreach (($backendUser->userGroups ?? []) as $group) {
            if (!is_array($group)) {
                continue;
            }
            foreach (explode(',', (string)($group['file_mountpoints'] ?? '')) as $fileMountUid) {
                $fileMountUid = (int)trim($fileMountUid);
                if ($fileMountUid > 0) {
                    $fileMountIds[$fileMountUid] = true;
                }
            }
        }

        return $fileMountIds;
    }

    private function listFitsGroupData(string $type, array $values): bool
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }
        foreach ($values as $value) {
            if (!$backendUser->check($type, (string)$value)) {
                return false;
            }
        }

        return true;
    }

    private function allowedLanguagesFitCurrentUser(array $allowedLanguages): bool
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }
        foreach ($allowedLanguages as $languageId) {
            if (!$backendUser->checkLanguageAccess($languageId)) {
                return false;
            }
        }

        return true;
    }

    private function categoryPermissionsFitCurrentUser(array $categoryPermissions): bool
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }
        $allowedCategoryIds = array_fill_keys(array_map('strval', $backendUser->getCategoryMountPoints()), true);
        foreach ($categoryPermissions as $categoryUid) {
            if (!isset($allowedCategoryIds[(string)$categoryUid])) {
                return false;
            }
        }

        return true;
    }

    private function workspacePermissionsFitCurrentUser(int $workspacePermissions): bool
    {
        if ($workspacePermissions === 0) {
            return true;
        }
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }
        $currentWorkspacePermissions = (int)($backendUser->groupData['workspace_perms'] ?? 0);

        return ($workspacePermissions & ~$currentWorkspacePermissions) === 0;
    }

    private function filterGroupValues(array $group, array $visiblePageIds, array $visibleFileMountIds): array
    {
        $group['pageTypeIds'] = array_values(array_filter(
            $group['pageTypeIds'] ?? [],
            fn(string|int $pageType): bool => $this->canViewPageType($pageType)
        ));
        $group['pageTypeCsv'] = implode(',', $group['pageTypeIds']);
        $visibleTablesSelect = [];
        $visibleTablesModify = [];
        foreach ($group['tablesSelect'] ?? [] as $tableName) {
            if ($this->canReadTable((string)$tableName)) {
                $visibleTablesSelect[] = (string)$tableName;
            }
        }
        foreach ($group['tablesModify'] ?? [] as $tableName) {
            if ($this->canWriteTable((string)$tableName)) {
                $visibleTablesModify[] = (string)$tableName;
                continue;
            }
            if ($this->canReadTable((string)$tableName)) {
                $visibleTablesSelect[] = (string)$tableName;
            }
        }
        $group['tablesSelect'] = array_values(array_unique($visibleTablesSelect));
        $group['tablesSelectCsv'] = implode(',', $group['tablesSelect']);
        $group['tablesModify'] = array_values(array_unique($visibleTablesModify));
        $group['tablesModifyCsv'] = implode(',', $group['tablesModify']);
        $group['moduleIds'] = array_values(array_filter(
            $group['moduleIds'] ?? [],
            fn(string|int $moduleId): bool => $this->canViewModule((string)$moduleId)
        ));
        $group['moduleCsv'] = implode(',', $group['moduleIds']);
        $group['dbMountIds'] = array_values(array_filter(
            $group['dbMountIds'] ?? [],
            static fn(string|int $pageId): bool => isset($visiblePageIds[(int)$pageId])
        ));
        $group['dbMountCsv'] = implode(',', $group['dbMountIds']);
        $group['fileMountIds'] = array_values(array_filter(
            $group['fileMountIds'] ?? [],
            static fn(string|int $fileMountId): bool => isset($visibleFileMountIds[(int)$fileMountId])
        ));
        $group['fileMountCsv'] = implode(',', $group['fileMountIds']);

        return $group;
    }

    private function filterUsers(array $users, array $data, array $visibleGroupIds): array
    {
        $visibleGroupIdSet = array_fill_keys(array_map('intval', $visibleGroupIds), true);
        $visibleGroupLabels = [];
        foreach ($data['groups'] ?? [] as $group) {
            $visibleGroupLabels[(int)$group['uid']] = (string)$group['title'];
        }
        $visibleModuleIds = $this->idSet($data['availableModules'] ?? []);
        $visiblePageIds = $this->uidSet($data['availablePages'] ?? []);
        $visibleFileMountIds = $this->uidSet($data['availableFileMounts'] ?? []);
        $filteredUsers = [];

        foreach ($users as $user) {
            if ((bool)($user['admin'] ?? false)) {
                continue;
            }
            $user['groupIds'] = array_values(array_filter(
                $user['groupIds'] ?? [],
                static fn(string|int $groupId): bool => isset($visibleGroupIdSet[(int)$groupId])
            ));
            $user['groupCsv'] = implode(',', $user['groupIds']);
            $user['groups'] = array_values(array_map(
                static fn(string|int $groupId): string => $visibleGroupLabels[(int)$groupId] ?? ('#' . $groupId),
                $user['groupIds']
            ));
            $user['moduleIds'] = array_values(array_filter(
                $user['moduleIds'] ?? [],
                static fn(string|int $moduleId): bool => isset($visibleModuleIds[(string)$moduleId])
            ));
            $user['moduleCsv'] = implode(',', $user['moduleIds']);
            $user['dbMountIds'] = array_values(array_filter(
                $user['dbMountIds'] ?? [],
                static fn(string|int $pageId): bool => isset($visiblePageIds[(int)$pageId])
            ));
            $user['dbMountCsv'] = implode(',', $user['dbMountIds']);
            $user['fileMountIds'] = array_values(array_filter(
                $user['fileMountIds'] ?? [],
                static fn(string|int $fileMountId): bool => isset($visibleFileMountIds[(int)$fileMountId])
            ));
            $user['fileMountCsv'] = implode(',', $user['fileMountIds']);

            $user['assignable'] = true;
            $filteredUsers[] = $user;
        }

        return $filteredUsers;
    }

    private function markUsersAssignable(array $users, array $assignableUsers): array
    {
        $assignableUserIds = array_fill_keys(array_map(
            static fn(array $user): int => (int)($user['uid'] ?? 0),
            $assignableUsers
        ), true);
        foreach ($users as $index => $user) {
            $users[$index]['assignable'] = isset($assignableUserIds[(int)($user['uid'] ?? 0)]);
        }

        return $users;
    }

    private function attachFilteredUsersToGroups(array $groups, array $users): array
    {
        foreach ($groups as $index => $group) {
            $groups[$index]['users'] = array_values(array_filter(
                $users,
                static fn(array $user): bool => in_array((int)$group['uid'], array_map('intval', $user['groupIds'] ?? []), true)
            ));
        }

        return $groups;
    }

    private function withAssignableFlags(array $data): array
    {
        foreach ($data['availablePageTypes'] ?? [] as $index => $pageType) {
            $data['availablePageTypes'][$index]['assignable'] = $this->canViewPageType($pageType['id'] ?? '');
        }
        foreach ($data['availableTables'] ?? [] as $index => $table) {
            $tableName = (string)($table['id'] ?? '');
            $canAssignRead = $this->canReadTable($tableName);
            $data['availableTables'][$index]['assignable'] = $canAssignRead;
            $data['availableTables'][$index]['canAssignRead'] = $canAssignRead;
            $data['availableTables'][$index]['canAssignWrite'] = $this->canWriteTable($tableName);
        }
        foreach ($data['availableModules'] ?? [] as $index => $module) {
            $data['availableModules'][$index]['assignable'] = $this->canUseBackendModuleRight((string)($module['id'] ?? ''));
        }
        foreach ($data['availablePages'] ?? [] as $index => $page) {
            $data['availablePages'][$index]['assignable'] = $this->canViewPage($page);
        }
        foreach ($data['availableFileMounts'] ?? [] as $index => $mount) {
            $data['availableFileMounts'][$index]['assignable'] = $this->canViewFileMount((int)($mount['uid'] ?? 0));
        }

        return $data;
    }

    private function filterModules(array $modules, bool $enforceUserPermissions): array
    {
        return array_values(array_filter(
            $modules,
            fn(array $module): bool => !$this->isRightsManagementModule((string)($module['id'] ?? ''))
                && (!$enforceUserPermissions || (bool)($module['assignable'] ?? false))
        ));
    }

    private function moduleRightFitsCurrentUser(string $moduleId): bool
    {
        return $this->canViewModule($moduleId) || $this->canKeepHiddenModuleRight($moduleId);
    }

    private function canKeepHiddenModuleRight(string $moduleId): bool
    {
        return $this->isRightsManagementModule($moduleId) && $this->canUseModule();
    }

    private function emptyViewData(array $data): array
    {
        foreach (['availableFileMounts', 'availableModules', 'availablePageTypes', 'availablePages', 'availableTables', 'groups', 'users'] as $key) {
            $data[$key] = [];
        }

        return $data;
    }

    private function withAccessFlags(array $data): array
    {
        $data['canSave'] = $this->canSave();
        $data['delegatedWritesEnabled'] = $this->delegatedWritesEnabled();

        return $data;
    }

    private function uidSet(array $rows): array
    {
        $set = [];
        foreach ($rows as $row) {
            $set[(int)$row['uid']] = true;
        }

        return $set;
    }

    private function idSet(array $rows): array
    {
        $set = [];
        foreach ($rows as $row) {
            $set[(string)$row['id']] = true;
        }

        return $set;
    }

    private function isRightsManagementModule(string $moduleId): bool
    {
        return in_array($moduleId, self::RIGHTS_MANAGEMENT_MODULES, true);
    }

    private function shouldEnforceUserPermissions(): bool
    {
        return $this->getBooleanConfiguration('enforceUserPermissions', false);
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        return ($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication ? $GLOBALS['BE_USER'] : null;
    }

    private function getBooleanConfiguration(string $key, bool $default): bool
    {
        $value = $this->getConfiguration()[$key] ?? ($default ? '1' : '0');

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function matchesAllowedBackendGroup(array $allowedGroups, BackendUserAuthentication $backendUser): bool
    {
        $allowedGroupIds = [];
        $allowedGroupTitles = [];
        foreach ($allowedGroups as $allowedGroup) {
            if (ctype_digit($allowedGroup)) {
                $allowedGroupIds[(int)$allowedGroup] = true;
                continue;
            }
            $allowedGroupTitles[strtolower($allowedGroup)] = true;
        }

        foreach (($backendUser->userGroupsUID ?? []) as $groupUid) {
            if (isset($allowedGroupIds[(int)$groupUid])) {
                return true;
            }
        }

        foreach (($backendUser->userGroups ?? []) as $group) {
            $groupTitle = strtolower(trim((string)($group['title'] ?? '')));
            if ($groupTitle !== '' && isset($allowedGroupTitles[$groupTitle])) {
                return true;
            }
        }

        return false;
    }

    private function getStringListConfiguration(string $key): array
    {
        $value = (string)($this->getConfiguration()[$key] ?? '');
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private function getConfiguration(): array
    {
        $defaults = [
            'moduleAccessGroupIds' => '',
            'enforceUserPermissions' => '0',
            'enableDelegatedWrites' => '0',
            'protectedGroupUids' => '',
        ];
        $pplConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ppl_rights_management'] ?? [];
        $pplConfiguration = is_array($pplConfiguration) ? $pplConfiguration : [];

        return array_replace($defaults, $pplConfiguration);
    }
}
