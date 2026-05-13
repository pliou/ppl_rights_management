<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Domain\Repository;

use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

abstract class AbstractRightsManagementRepository
{
    public function __construct(
        protected readonly ConnectionPool $connectionPool,
        protected readonly ModuleProvider $moduleProvider
    ) {}

    public function getGroups(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_groups');
        $rows = $queryBuilder
            ->select('uid', 'pid', 'title', 'description', 'hidden', 'subgroup', 'pagetypes_select', 'tables_select', 'tables_modify', 'non_exclude_fields', 'explicit_allowdeny', 'allowed_languages', 'groupMods', 'custom_options', 'db_mountpoints', 'file_mountpoints', 'file_permissions', 'workspace_perms', 'category_perms', 'TSconfig')
            ->from('be_groups')
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->orderBy('title', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(fn(array $row): array => $this->normalizeGroup($row), $rows);
    }

    public function getBackendUsers(array $groups): array
    {
        $groupMap = $this->mapByUid($groups);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $rows = $queryBuilder
            ->select('uid', 'username', 'realName', 'email', 'admin', 'disable', 'usergroup', 'description', 'userMods', 'db_mountpoints', 'file_mountpoints', 'file_permissions')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->neq('username', $queryBuilder->createNamedParameter('_cli_'))
            )
            ->orderBy('username', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(function (array $row) use ($groupMap): array {
            $groupIds = $this->splitCsv($row['usergroup'] ?? '');
            $moduleIds = $this->splitCsv($row['userMods'] ?? '');
            $dbMountIds = $this->splitCsv($row['db_mountpoints'] ?? '');
            $fileMountIds = $this->splitCsv($row['file_mountpoints'] ?? '');
            $filePermissions = $this->splitCsv($row['file_permissions'] ?? '');
            return [
                'uid' => (int)$row['uid'],
                'username' => (string)$row['username'],
                'realName' => (string)($row['realName'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
                'description' => (string)($row['description'] ?? ''),
                'admin' => (bool)$row['admin'],
                'disabled' => (bool)$row['disable'],
                'groupIds' => $groupIds,
                'groupCsv' => implode(',', $groupIds),
                'groups' => $this->labelsForIds($groupIds, $groupMap),
                'moduleIds' => $moduleIds,
                'moduleCsv' => implode(',', $moduleIds),
                'dbMountIds' => $dbMountIds,
                'dbMountCsv' => implode(',', $dbMountIds),
                'fileMountIds' => $fileMountIds,
                'fileMountCsv' => implode(',', $fileMountIds),
                'filePermissions' => $filePermissions,
                'filePermissionCsv' => implode(',', $filePermissions),
            ];
        }, $rows);
    }

    public function getTables(): array
    {
        $tables = [];
        foreach (($GLOBALS['TCA'] ?? []) as $tableName => $configuration) {
            $tables[] = [
                'id' => (string)$tableName,
                'label' => $this->translate((string)($configuration['ctrl']['title'] ?? $tableName)),
            ];
        }
        usort($tables, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $tables;
    }

    public function getPageTypes(): array
    {
        $items = $GLOBALS['TCA']['pages']['columns']['doktype']['config']['items'] ?? [];
        $pageTypes = [];
        foreach ($items as $item) {
            if (($item['value'] ?? '--div--') === '--div--') {
                continue;
            }
            $pageTypes[] = [
                'id' => (string)$item['value'],
                'label' => $this->translate((string)($item['label'] ?? $item['value'])),
            ];
        }

        return $pageTypes;
    }

    public function getBackendModules(): array
    {
        $modules = [];
        foreach ($this->moduleProvider->getModules(null, true, false) as $module) {
            if (!$module instanceof ModuleInterface || $module->getIdentifier() === '') {
                continue;
            }
            $modules[] = [
                'id' => $module->getIdentifier(),
                'label' => $this->translate($module->getTitle()) ?: $module->getIdentifier(),
            ];
        }
        usort($modules, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $modules;
    }

    public function getPages(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $rows = $queryBuilder
            ->select('uid', 'pid', 'title', 'doktype', 'hidden', 'sorting')
            ->addSelect('perms_userid', 'perms_user', 'perms_groupid', 'perms_group', 'perms_everybody')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->orderBy('pid', 'ASC')
            ->addOrderBy('sorting', 'ASC')
            ->addOrderBy('title', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): array => [
            'uid' => (int)$row['uid'],
            'pid' => (int)$row['pid'],
            'label' => (string)$row['title'],
            'meta' => 'Page ID ' . (int)$row['uid'],
            'disabled' => (bool)$row['hidden'],
            'sorting' => (int)$row['sorting'],
            'perms_userid' => (int)$row['perms_userid'],
            'perms_user' => (int)$row['perms_user'],
            'perms_groupid' => (int)$row['perms_groupid'],
            'perms_group' => (int)$row['perms_group'],
            'perms_everybody' => (int)$row['perms_everybody'],
        ], $rows);
    }

    public function getFileMounts(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_filemounts');
        $rows = $queryBuilder
            ->select('uid', 'title', 'identifier', 'hidden', 'read_only', 'sorting')
            ->from('sys_filemounts')
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->orderBy('sorting', 'ASC')
            ->addOrderBy('title', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): array => [
            'uid' => (int)$row['uid'],
            'label' => (string)$row['title'],
            'meta' => (string)($row['identifier'] ?? ''),
            'disabled' => (bool)$row['hidden'],
            'readOnly' => (bool)($row['read_only'] ?? false),
        ], $rows);
    }

    public function enrichGroups(array $groups, array $users): array
    {
        $groupMap = $this->mapByUid($groups);
        foreach ($groups as $index => $group) {
            $groups[$index]['subgroups'] = $this->labelsForIds($group['subgroupIds'], $groupMap);
            $groups[$index]['users'] = array_values(array_filter(
                $users,
                static fn(array $user): bool => in_array($group['uid'], $user['groupIds'], true)
            ));
        }

        return $groups;
    }

    protected function normalizeGroup(array $row): array
    {
        $subgroupIds = $this->splitCsv($row['subgroup'] ?? '');
        $dbMountIds = $this->splitCsv($row['db_mountpoints'] ?? '');
        $fileMountIds = $this->splitCsv($row['file_mountpoints'] ?? '');
        $filePermissions = $this->splitCsv($row['file_permissions'] ?? '');

        return [
            'uid' => (int)$row['uid'],
            'pid' => (int)$row['pid'],
            'title' => (string)$row['title'],
            'description' => (string)($row['description'] ?? ''),
            'hidden' => (bool)$row['hidden'],
            'subgroupIds' => $subgroupIds,
            'subgroupCsv' => implode(',', $subgroupIds),
            'pageTypeIds' => $this->splitCsv($row['pagetypes_select'] ?? ''),
            'pageTypeCsv' => (string)($row['pagetypes_select'] ?? ''),
            'tablesSelect' => $this->splitCsv($row['tables_select'] ?? ''),
            'tablesSelectCsv' => (string)($row['tables_select'] ?? ''),
            'tablesModify' => $this->splitCsv($row['tables_modify'] ?? ''),
            'tablesModifyCsv' => (string)($row['tables_modify'] ?? ''),
            'fieldPermissions' => $this->splitCsv($row['non_exclude_fields'] ?? ''),
            'explicitAllowDeny' => $this->splitCsv($row['explicit_allowdeny'] ?? ''),
            'allowedLanguages' => $this->splitCsv($row['allowed_languages'] ?? ''),
            'moduleIds' => $this->splitCsv($row['groupMods'] ?? ''),
            'moduleCsv' => (string)($row['groupMods'] ?? ''),
            'customOptions' => $this->splitCsv($row['custom_options'] ?? ''),
            'dbMountIds' => $dbMountIds,
            'dbMountCsv' => implode(',', $dbMountIds),
            'fileMountIds' => $fileMountIds,
            'fileMountCsv' => implode(',', $fileMountIds),
            'filePermissions' => $filePermissions,
            'filePermissionCsv' => implode(',', $filePermissions),
            'workspacePermissions' => (int)($row['workspace_perms'] ?? 0),
            'categoryPermissions' => $this->splitCsv($row['category_perms'] ?? ''),
            'categoryPermissionCsv' => (string)($row['category_perms'] ?? ''),
            'TSconfig' => (string)($row['TSconfig'] ?? ''),
        ];
    }

    protected function mapByUid(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['uid']] = $row;
        }

        return $map;
    }

    protected function labelsForIds(array $ids, array $map): array
    {
        $labels = [];
        foreach ($ids as $id) {
            $labels[] = $map[(int)$id]['title'] ?? ('#' . $id);
        }

        return $labels;
    }

    protected function splitCsv(string|int|null $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(string $item): string|int => ctype_digit($item) ? (int)$item : $item,
            array_map('trim', explode(',', (string)$value))
        ), static fn(string|int $item): bool => $item !== ''));
    }

    protected function translate(string $label): string
    {
        if (str_starts_with($label, 'LLL:') && isset($GLOBALS['LANG'])) {
            return $GLOBALS['LANG']->sL($label) ?: $label;
        }

        return $label;
    }
}
