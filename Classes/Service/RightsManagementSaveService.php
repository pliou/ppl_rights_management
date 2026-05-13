<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Service;

use Ppl\PplRightsManagement\Domain\Repository\OverviewManagementRepository;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

class RightsManagementSaveService
{
    private const INSERT_PLUGIN_CONTENT_TYPE_ALLOW = 'tt_content:CType:list';
    private const LEGACY_PLUGIN_ALLOW_PREFIX = 'tt_content:list_type:';

    private const READONLY_SCOPES = [
        'overview' => true,
        'group-backend-user-management' => true,
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly OverviewManagementRepository $repository,
        private readonly RightsManagementAccessService $accessService
    ) {}

    public function save(string $scope, array $payload): array
    {
        $scope = trim($scope);
        if ($scope === '' || isset(self::READONLY_SCOPES[$scope])) {
            throw new RuntimeException('Diese Ansicht ist nur lesend.');
        }
        $backendUser = $this->getBackendUser();
        if ($backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin()) {
            // Admin writes use TYPO3 DataHandler directly; delegated writes pass our policy checks first.
            [$count, $history] = $this->saveAdminWithDataHandler($scope, $payload);

            return [
                'count' => $count,
                'message' => $count === 1 ? '1 Änderung gespeichert.' : $count . ' Änderungen gespeichert.',
                'history' => $history,
            ];
        }
        if (!$this->accessService->canSave()) {
            throw new RuntimeException('Nur-Lese-Modus. Wenn etwas geändert werden muss, sprechen Sie bitte mit Ihrem Admin.');
        }

        $context = $this->buildContextForScope($scope, $payload);
        [$count, $history] = $this->saveDelegatedWithDataHandler($scope, $payload, $context);

        return [
            'count' => $count,
            'message' => $count === 1 ? '1 Änderung gespeichert.' : $count . ' Änderungen gespeichert.',
            'history' => $history,
        ];
    }

    private function saveAdminWithDataHandler(string $scope, array $payload): array
    {
        [$dataMap, $commandMap, $count] = $this->buildAdminDataHandlerMaps($scope, $payload);
        $this->assertDataHandlerMapsAllowed($scope, $dataMap, $commandMap);
        $dataMap = $this->addRequiredInsertPluginContentTypeAllow($dataMap);
        $history = $this->buildHistoryContext($dataMap, $commandMap);
        $runResult = $this->runDataHandler($dataMap, $commandMap, $count);

        return [$runResult['count'], $this->finalizeHistoryContext($history, $dataMap, $runResult['newIdMap'])];
    }

    private function saveDelegatedWithDataHandler(string $scope, array $payload, array $context): array
    {
        [$dataMap, $commandMap, $count] = $this->buildDelegatedDataHandlerMaps($scope, $payload, $context);
        $this->assertDataHandlerMapsAllowed($scope, $dataMap, $commandMap);
        $dataMap = $this->addRequiredInsertPluginContentTypeAllow($dataMap);
        $history = $this->buildHistoryContext($dataMap, $commandMap);
        $runResult = $this->runDataHandler($dataMap, $commandMap, $count);

        return [$runResult['count'], $this->finalizeHistoryContext($history, $dataMap, $runResult['newIdMap'])];
    }

    private function runDataHandler(array $dataMap, array $commandMap, int $count): array
    {
        if ($count === 0) {
            return [
                'count' => 0,
                'newIdMap' => [],
            ];
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        // Our module validates the assignment rules first; DataHandler is used as TYPO3-conform writer afterwards.
        $dataHandler->bypassAccessCheckForRecords = true;
        $dataHandler->start($dataMap, $commandMap, $this->getPrivilegedDataHandlerBackendUser());
        if ($dataMap !== []) {
            $dataHandler->process_datamap();
        }
        if ($commandMap !== []) {
            $dataHandler->process_cmdmap();
        }

        $blockingErrors = $this->blockingDataHandlerErrors($dataHandler->errorLog);
        if ($blockingErrors !== []) {
            throw new RuntimeException('TYPO3 hat das Speichern abgelehnt: ' . implode(' | ', $blockingErrors));
        }
        foreach ($dataMap as $records) {
            foreach (array_keys($records) as $recordId) {
                $recordId = (string)$recordId;
                if (str_starts_with($recordId, 'NEW') && !isset($dataHandler->substNEWwithIDs[$recordId])) {
                    throw new RuntimeException('TYPO3 hat den neuen Datensatz nicht angelegt.');
                }
            }
        }

        return [
            'count' => $count,
            'newIdMap' => $dataHandler->substNEWwithIDs,
        ];
    }

    private function blockingDataHandlerErrors(array $errorLog): array
    {
        $messages = [];
        foreach ($errorLog as $message) {
            $message = trim((string)$message);
            if ($message === '') {
                continue;
            }
            if (str_contains($message, 'Editing of at least one plugin was enabled but editing the page content type "Insert Plugin" is still disallowed.')) {
                continue;
            }
            if (!in_array($message, $messages, true)) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private function addRequiredInsertPluginContentTypeAllow(array $dataMap): array
    {
        if (($dataMap['be_groups'] ?? []) === [] || !$this->hasColumn('be_groups', 'explicit_allowdeny')) {
            return $dataMap;
        }

        foreach ($dataMap['be_groups'] as $recordId => $fields) {
            if (!is_array($fields)) {
                continue;
            }

            $explicitAllowDeny = null;
            if (array_key_exists('explicit_allowdeny', $fields)) {
                $explicitAllowDeny = (string)$fields['explicit_allowdeny'];
            } elseif (!str_starts_with((string)$recordId, 'NEW')) {
                $record = $this->fetchRecord('be_groups', (int)$recordId, ['explicit_allowdeny']);
                $explicitAllowDeny = (string)($record['explicit_allowdeny'] ?? '');
            }

            if ($explicitAllowDeny === null) {
                continue;
            }

            $normalized = $this->withInsertPluginContentTypeAllow($explicitAllowDeny);
            if ($normalized !== $explicitAllowDeny) {
                if (!$this->canAssignInsertPluginContentTypeAllow($explicitAllowDeny)) {
                    throw new RuntimeException('Plugin-Bearbeitung ist aktiviert, aber der Inhaltstyp "Insert Plugin" darf von diesem Benutzer nicht vergeben werden.');
                }
                $dataMap['be_groups'][$recordId]['explicit_allowdeny'] = $normalized;
            }
        }

        return $dataMap;
    }

    private function withInsertPluginContentTypeAllow(string $explicitAllowDeny): string
    {
        $values = $this->stringList(explode(',', $explicitAllowDeny));
        $hasLegacyPluginAllow = false;
        foreach ($values as $value) {
            if ($value === self::INSERT_PLUGIN_CONTENT_TYPE_ALLOW) {
                return $explicitAllowDeny;
            }
            if (str_starts_with($value, self::LEGACY_PLUGIN_ALLOW_PREFIX)) {
                $hasLegacyPluginAllow = true;
            }
        }

        if (!$hasLegacyPluginAllow) {
            return $explicitAllowDeny;
        }

        $values[] = self::INSERT_PLUGIN_CONTENT_TYPE_ALLOW;

        return $this->csv($values);
    }

    private function canAssignInsertPluginContentTypeAllow(string $explicitAllowDeny): bool
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }
        if ($backendUser->isAdmin() || $backendUser->check('explicit_allowdeny', self::INSERT_PLUGIN_CONTENT_TYPE_ALLOW)) {
            return true;
        }

        foreach ($this->stringList(explode(',', $explicitAllowDeny)) as $value) {
            if (str_starts_with($value, self::LEGACY_PLUGIN_ALLOW_PREFIX)
                && $backendUser->check('explicit_allowdeny', $value)
            ) {
                return true;
            }
        }

        return false;
    }

    private function assertDataHandlerMapsAllowed(string $scope, array $dataMap, array $commandMap): void
    {
        $allowedFields = match ($scope) {
            'backend-user-management' => [
                'be_users' => ['usergroup', 'userMods', 'db_mountpoints', 'file_mountpoints'],
            ],
            'group-management' => [
                'be_groups' => [
                    'pid',
                    'title',
                    'description',
                    'hidden',
                    'subgroup',
                    'pagetypes_select',
                    'tables_select',
                    'tables_modify',
                    'non_exclude_fields',
                    'explicit_allowdeny',
                    'allowed_languages',
                    'groupMods',
                    'custom_options',
                    'db_mountpoints',
                    'file_mountpoints',
                    'file_permissions',
                    'TSconfig',
                ],
            ],
            'group-rights-management' => [
                'be_groups' => ['pagetypes_select', 'tables_select', 'tables_modify'],
            ],
            'module-management' => [
                'be_groups' => ['groupMods'],
            ],
            'group-rights-inheritance-management' => [
                'be_groups' => ['subgroup'],
            ],
            'mount-management' => [
                'be_groups' => ['db_mountpoints', 'file_mountpoints'],
            ],
            default => throw new RuntimeException('Unbekannter Speicherbereich: ' . $scope),
        };
        $allowedCommands = $scope === 'group-management' ? ['be_groups' => ['delete' => true]] : [];

        foreach ($dataMap as $tableName => $records) {
            $tableName = (string)$tableName;
            if (!isset($allowedFields[$tableName])) {
                throw new RuntimeException('Tabelle ' . $tableName . ' darf in diesem Speicherbereich nicht geändert werden.');
            }
            $fieldSet = array_fill_keys($allowedFields[$tableName], true);
            foreach ((array)$records as $recordId => $fields) {
                if (!is_array($fields)) {
                    throw new RuntimeException('Ungültige Speicherdaten für ' . $tableName . ':' . (string)$recordId . '.');
                }
                foreach (array_keys($fields) as $fieldName) {
                    if (!isset($fieldSet[(string)$fieldName])) {
                        throw new RuntimeException('Feld ' . $tableName . '.' . (string)$fieldName . ' darf in diesem Speicherbereich nicht geändert werden.');
                    }
                }
            }
        }

        foreach ($commandMap as $tableName => $records) {
            $tableName = (string)$tableName;
            if (!isset($allowedCommands[$tableName])) {
                throw new RuntimeException('Kommandos für Tabelle ' . $tableName . ' sind in diesem Speicherbereich nicht erlaubt.');
            }
            foreach ((array)$records as $uid => $commands) {
                if (!is_array($commands)) {
                    throw new RuntimeException('Ungültiges Kommando für ' . $tableName . ':' . (string)$uid . '.');
                }
                foreach (array_keys($commands) as $commandName) {
                    if (!isset($allowedCommands[$tableName][(string)$commandName])) {
                        throw new RuntimeException('Kommando ' . (string)$commandName . ' ist für ' . $tableName . ' nicht erlaubt.');
                    }
                }
            }
        }
    }

    private function buildHistoryContext(array $dataMap, array $commandMap): array
    {
        $context = [
            'before' => [],
        ];

        foreach ($dataMap as $tableName => $records) {
            foreach ($records as $recordId => $fields) {
                $recordId = (string)$recordId;
                if (str_starts_with($recordId, 'NEW')) {
                    continue;
                }
                $uid = (int)$recordId;
                $before = $this->fetchRecord($tableName, $uid, array_keys((array)$fields));
                if ($before === []) {
                    continue;
                }
                $context['before'][$tableName][$uid] = $before;
            }
        }

        foreach ($commandMap as $tableName => $records) {
            foreach ($records as $uid => $commands) {
                $uid = (int)$uid;
                if ($uid <= 0 || !is_array($commands) || !isset($commands['delete'])) {
                    continue;
                }
                $before = $this->fetchRecord($tableName, $uid);
                if ($before !== []) {
                    $context['before'][$tableName][$uid] = $before;
                }
            }
        }

        return $context;
    }

    private function finalizeHistoryContext(array $context, array $dataMap, array $newIdMap): array
    {
        $after = [];
        foreach ($dataMap as $tableName => $records) {
            foreach ($records as $recordId => $fields) {
                $recordId = (string)$recordId;
                $uid = str_starts_with($recordId, 'NEW') ? (int)($newIdMap[$recordId] ?? 0) : (int)$recordId;
                if ($uid <= 0) {
                    continue;
                }
                $afterRecord = $this->fetchRecord($tableName, $uid, array_unique(array_merge(['uid'], array_keys((array)$fields))));
                if ($afterRecord !== []) {
                    $after[$tableName][$uid] = $afterRecord;
                }
            }
        }

        return [
            'payloadBefore' => $context['before'],
            'payloadAfter' => $after,
        ];
    }

    private function fetchRecord(string $tableName, int $uid, array $fields = []): array
    {
        if ($uid <= 0) {
            return [];
        }
        $requestedFields = [];
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            if ($fields === []) {
                $queryBuilder->select('*');
            } else {
                $fields = array_values(array_filter(array_unique(array_merge(['uid'], $fields)), static fn(string $field): bool => $field !== ''));
                $requestedFields = array_keys($this->filterExistingColumns($tableName, array_fill_keys($fields, true)));
                if ($requestedFields === []) {
                    $queryBuilder->select('*');
                } else {
                    $queryBuilder->select(...$requestedFields);
                }
            }
            $row = $queryBuilder
                ->from($tableName)
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, \PDO::PARAM_INT)))
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Throwable) {
            $row = $this->fetchFullRecord($tableName, $uid);
            if ($row === []) {
                return [];
            }
        }

        if (!is_array($row)) {
            return [];
        }
        if ($requestedFields !== []) {
            return array_intersect_key($row, array_fill_keys($requestedFields, true));
        }

        return $row;
    }

    private function fetchFullRecord(string $tableName, int $uid): array
    {
        if ($uid <= 0) {
            return [];
        }
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $row = $queryBuilder
                ->select('*')
                ->from($tableName)
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, \PDO::PARAM_INT)))
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Throwable) {
            return [];
        }

        return is_array($row) ? $row : [];
    }

    private function hasColumn(string $tableName, string $columnName): bool
    {
        return $this->filterExistingColumns($tableName, [$columnName => true]) !== [];
    }

    private function getPrivilegedDataHandlerBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication || $backendUser->isAdmin()) {
            return $backendUser;
        }

        $privilegedBackendUser = clone $backendUser;
        $privilegedBackendUser->user['admin'] = 1;

        return $privilegedBackendUser;
    }

    private function buildAdminDataHandlerMaps(string $scope, array $payload): array
    {
        return match ($scope) {
            'backend-user-management' => $this->buildAdminBackendUserMaps($payload),
            'group-management' => $this->buildAdminGroupManagementMaps($payload),
            'group-rights-management' => $this->buildAdminGroupRightsMaps($payload),
            'module-management' => $this->buildAdminModuleMaps($payload),
            'group-rights-inheritance-management' => $this->buildAdminInheritanceMaps($payload),
            'mount-management' => $this->buildAdminMountMaps($payload),
            default => throw new RuntimeException('Unbekannter Speicherbereich: ' . $scope),
        };
    }

    private function buildDelegatedDataHandlerMaps(string $scope, array $payload, array $context): array
    {
        return match ($scope) {
            'backend-user-management' => $this->buildDelegatedBackendUserMaps($payload, $context),
            'group-management' => $this->buildDelegatedGroupManagementMaps($payload, $context),
            'group-rights-management' => $this->buildDelegatedGroupRightsMaps($payload, $context),
            'module-management' => $this->buildDelegatedModuleMaps($payload, $context),
            'group-rights-inheritance-management' => $this->buildDelegatedInheritanceMaps($payload, $context),
            'mount-management' => $this->buildDelegatedMountMaps($payload, $context),
            default => throw new RuntimeException('Unbekannter Speicherbereich: ' . $scope),
        };
    }

    private function buildAdminBackendUserMaps(array $payload): array
    {
        $dataMap = [];
        foreach ($this->listPayload($payload['users'] ?? []) as $item) {
            $uid = (int)($item['uid'] ?? 0);
            if ($uid <= 0) {
                throw new RuntimeException('Backend-User ohne UID kann nicht gespeichert werden.');
            }
            $dataMap['be_users'][$uid] = $this->filterExistingColumns('be_users', [
                'usergroup' => $this->csv($this->intList($item['groups'] ?? [])),
                'userMods' => $this->csv($this->stringList($item['modules'] ?? [])),
                'db_mountpoints' => $this->csv($this->intList($item['dbMounts'] ?? [])),
                'file_mountpoints' => $this->csv($this->intList($item['fileMounts'] ?? [])),
            ]);
        }

        return [$dataMap, [], count($dataMap['be_users'] ?? [])];
    }

    private function buildAdminGroupManagementMaps(array $payload): array
    {
        $dataMap = [];
        $commandMap = [];
        foreach ($this->listPayload($payload['create'] ?? []) as $item) {
            $title = $this->cleanText((string)($item['title'] ?? ''), 50);
            $description = $this->cleanText((string)($item['description'] ?? ''), 255);
            if ($title === '') {
                throw new RuntimeException('Gruppentitel darf nicht leer sein.');
            }
            $newId = StringUtility::getUniqueId('NEW');
            $dataMap['be_groups'][$newId] = $this->filterExistingColumns('be_groups', [
                'pid' => 0,
                'title' => $title,
                'description' => $description,
                'hidden' => 0,
                'subgroup' => '',
                'pagetypes_select' => '',
                'tables_select' => '',
                'tables_modify' => '',
                'non_exclude_fields' => '',
                'explicit_allowdeny' => '',
                'allowed_languages' => '',
                'groupMods' => '',
                'custom_options' => '',
                'db_mountpoints' => '',
                'file_mountpoints' => '',
                'file_permissions' => '',
                'TSconfig' => '',
            ]);
        }
        foreach ($this->intList($payload['delete'] ?? []) as $uid) {
            $commandMap['be_groups'][$uid] = ['delete' => 1];
        }

        return [$dataMap, $commandMap, count($dataMap['be_groups'] ?? []) + count($commandMap['be_groups'] ?? [])];
    }

    private function buildAdminGroupRightsMaps(array $payload): array
    {
        $dataMap = [];
        foreach ($this->listPayload($payload['groups'] ?? []) as $item) {
            $uid = (int)($item['uid'] ?? 0);
            if ($uid <= 0) {
                throw new RuntimeException('Gruppe ohne UID kann nicht gespeichert werden.');
            }
            [$tablesSelect, $tablesModify] = $this->tablePermissionLists(is_array($item['tables'] ?? null) ? $item['tables'] : []);
            $dataMap['be_groups'][$uid] = $this->filterExistingColumns('be_groups', [
                'pagetypes_select' => $this->csv($this->stringList($item['pageTypes'] ?? [])),
                'tables_select' => $this->csv($tablesSelect),
                'tables_modify' => $this->csv($tablesModify),
            ]);
        }

        return [$dataMap, [], count($dataMap['be_groups'] ?? [])];
    }

    private function buildAdminModuleMaps(array $payload): array
    {
        $dataMap = [];
        foreach ($this->listPayload($payload['groups'] ?? []) as $item) {
            $uid = (int)($item['uid'] ?? 0);
            if ($uid <= 0) {
                throw new RuntimeException('Gruppe ohne UID kann nicht gespeichert werden.');
            }
            $dataMap['be_groups'][$uid] = $this->filterExistingColumns('be_groups', [
                'groupMods' => $this->csv($this->stringList($item['modules'] ?? [])),
            ]);
        }

        return [$dataMap, [], count($dataMap['be_groups'] ?? [])];
    }

    private function buildAdminInheritanceMaps(array $payload): array
    {
        $uid = (int)($payload['groupUid'] ?? 0);
        if ($uid <= 0) {
            throw new RuntimeException('Gruppe ohne UID kann nicht gespeichert werden.');
        }
        $submitted = $this->intList($payload['inherited'] ?? []);
        foreach ($submitted as $subgroupUid) {
            if ($subgroupUid === $uid) {
                throw new RuntimeException('Eine Gruppe kann sich nicht selbst erben.');
            }
        }
        $groupsByUid = $this->mapByUid($this->repository->getGroups());
        if ($this->wouldCreateInheritanceCycle($uid, $submitted, $groupsByUid)) {
            throw new RuntimeException('Diese Vererbung würde eine Schleife erzeugen.');
        }

        return [[
            'be_groups' => [
                $uid => $this->filterExistingColumns('be_groups', [
                    'subgroup' => $this->csv($submitted),
                ]),
            ],
        ], [], 1];
    }

    private function buildAdminMountMaps(array $payload): array
    {
        $dataMap = [];
        foreach ($this->listPayload($payload['groups'] ?? []) as $item) {
            $uid = (int)($item['uid'] ?? 0);
            if ($uid <= 0) {
                throw new RuntimeException('Gruppe ohne UID kann nicht gespeichert werden.');
            }
            $dataMap['be_groups'][$uid] = $this->filterExistingColumns('be_groups', [
                'db_mountpoints' => $this->csv($this->intList($item['dbMounts'] ?? [])),
                'file_mountpoints' => $this->csv($this->intList($item['fileMounts'] ?? [])),
            ]);
        }

        return [$dataMap, [], count($dataMap['be_groups'] ?? [])];
    }

    private function buildDelegatedBackendUserMaps(array $payload, array $context): array
    {
        $dataMap = [];
        foreach ($this->listPayload($payload['users'] ?? []) as $item) {
            $uid = (int)($item['uid'] ?? 0);
            $user = $context['usersByUid'][$uid] ?? null;
            if (!is_array($user) || !$this->canTouchUser($uid, $user, $context)) {
                throw new RuntimeException('Backend-User UID ' . $uid . ' darf nicht geändert werden.');
            }

            $dataMap['be_users'][$uid] = $this->filterExistingColumns('be_users', [
                'usergroup' => $this->csv($this->mergeVisibleInts(
                    $user['groupIds'] ?? [],
                    $this->intList($item['groups'] ?? []),
                    $context['assignableGroupSet']
                )),
                'userMods' => $this->csv($this->mergeVisibleStrings(
                    $user['moduleIds'] ?? [],
                    $this->stringList($item['modules'] ?? []),
                    $context['moduleSet']
                )),
                'db_mountpoints' => $this->csv($this->mergeVisibleInts(
                    $user['dbMountIds'] ?? [],
                    $this->intList($item['dbMounts'] ?? []),
                    $context['pageSet']
                )),
                'file_mountpoints' => $this->csv($this->mergeVisibleInts(
                    $user['fileMountIds'] ?? [],
                    $this->intList($item['fileMounts'] ?? []),
                    $context['fileMountSet']
                )),
            ]);
        }

        return [$dataMap, [], count($dataMap['be_users'] ?? [])];
    }

    private function buildDelegatedGroupManagementMaps(array $payload, array $context): array
    {
        $dataMap = [];
        $commandMap = [];
        foreach ($this->listPayload($payload['create'] ?? []) as $item) {
            $title = $this->cleanText((string)($item['title'] ?? ''), 50);
            $description = $this->cleanText((string)($item['description'] ?? ''), 255);
            if ($title === '') {
                throw new RuntimeException('Gruppentitel darf nicht leer sein.');
            }
            $newId = StringUtility::getUniqueId('NEW');
            $dataMap['be_groups'][$newId] = $this->filterExistingColumns('be_groups', [
                'pid' => 0,
                'title' => $title,
                'description' => $description,
                'hidden' => 0,
                'subgroup' => '',
                'pagetypes_select' => '',
                'tables_select' => '',
                'tables_modify' => '',
                'non_exclude_fields' => '',
                'explicit_allowdeny' => '',
                'allowed_languages' => '',
                'groupMods' => '',
                'custom_options' => '',
                'db_mountpoints' => '',
                'file_mountpoints' => '',
                'file_permissions' => '',
                'TSconfig' => '',
            ]);
        }

        foreach ($this->intList($payload['delete'] ?? []) as $uid) {
            $group = $context['groupsByUid'][$uid] ?? null;
            if (!is_array($group) || !isset($context['assignableGroupSet'][(string)$uid])) {
                throw new RuntimeException('Gruppe UID ' . $uid . ' darf nicht gelöscht werden.');
            }
            $this->assertGroupHasNoReferences($uid, $context);
            $commandMap['be_groups'][$uid] = ['delete' => 1];
        }

        return [$dataMap, $commandMap, count($dataMap['be_groups'] ?? []) + count($commandMap['be_groups'] ?? [])];
    }

    private function buildDelegatedGroupRightsMaps(array $payload, array $context): array
    {
        $dataMap = [];
        foreach ($this->listPayload($payload['groups'] ?? []) as $item) {
            $uid = (int)($item['uid'] ?? 0);
            $group = $this->requireEditableGroup($uid, $context);
            $tableModes = is_array($item['tables'] ?? null) ? $item['tables'] : [];

            foreach ($tableModes as $tableName => $mode) {
                $tableName = (string)$tableName;
                if (!isset($context['tableSet'][$tableName])) {
                    throw new RuntimeException('Tabelle ' . $tableName . ' darf nicht geändert werden.');
                }
                $mode = (string)$mode;
                if ($mode === 'write') {
                    if (!$this->accessService->canWriteTable($tableName)) {
                        throw new RuntimeException('Schreibrecht für Tabelle ' . $tableName . ' darf nicht vergeben werden.');
                    }
                    continue;
                }
                if ($mode === 'read') {
                    if (!$this->accessService->canReadTable($tableName)) {
                        throw new RuntimeException('Leserecht für Tabelle ' . $tableName . ' darf nicht vergeben werden.');
                    }
                    continue;
                }
                if ($mode !== 'none') {
                    throw new RuntimeException('Ungültiger Tabellenmodus für ' . $tableName . '.');
                }
            }
            [$tablesSelect, $tablesModify] = $this->tablePermissionLists($tableModes);

            $dataMap['be_groups'][$uid] = $this->filterExistingColumns('be_groups', [
                'pagetypes_select' => $this->csv($this->mergeVisibleStrings(
                    $group['pageTypeIds'] ?? [],
                    $this->stringList($item['pageTypes'] ?? []),
                    $context['pageTypeSet']
                )),
                'tables_select' => $this->csv($this->mergeVisibleStrings(
                    $group['tablesSelect'] ?? [],
                    $tablesSelect,
                    $context['tableSet']
                )),
                'tables_modify' => $this->csv($this->mergeVisibleStrings(
                    $group['tablesModify'] ?? [],
                    $tablesModify,
                    $context['tableSet']
                )),
            ]);
        }

        return [$dataMap, [], count($dataMap['be_groups'] ?? [])];
    }

    private function buildDelegatedModuleMaps(array $payload, array $context): array
    {
        $dataMap = [];
        foreach ($this->listPayload($payload['groups'] ?? []) as $item) {
            $uid = (int)($item['uid'] ?? 0);
            $group = $this->requireEditableGroup($uid, $context);
            $dataMap['be_groups'][$uid] = $this->filterExistingColumns('be_groups', [
                'groupMods' => $this->csv($this->mergeVisibleStrings(
                    $group['moduleIds'] ?? [],
                    $this->stringList($item['modules'] ?? []),
                    $context['moduleSet']
                )),
            ]);
        }

        return [$dataMap, [], count($dataMap['be_groups'] ?? [])];
    }

    private function buildDelegatedInheritanceMaps(array $payload, array $context): array
    {
        $uid = (int)($payload['groupUid'] ?? 0);
        $group = $this->requireEditableGroup($uid, $context);
        $submitted = $this->intList($payload['inherited'] ?? []);
        $currentSubgroupSet = array_fill_keys(array_map('strval', $this->intList($group['subgroupIds'] ?? [])), true);
        foreach ($submitted as $subgroupUid) {
            if ($subgroupUid === $uid) {
                throw new RuntimeException('Eine Gruppe kann sich nicht selbst erben.');
            }
            if (!isset($context['assignableGroupSet'][(string)$subgroupUid]) && !isset($currentSubgroupSet[(string)$subgroupUid])) {
                throw new RuntimeException('Geerbte Gruppe UID ' . $subgroupUid . ' darf nicht zugewiesen werden.');
            }
        }
        if ($this->wouldCreateInheritanceCycle($uid, $submitted, $context['groupsByUid'])) {
            throw new RuntimeException('Diese Vererbung würde eine Schleife erzeugen.');
        }

        return [[
            'be_groups' => [
                $uid => $this->filterExistingColumns('be_groups', [
                    'subgroup' => $this->csv($this->mergeVisibleInts(
                        $group['subgroupIds'] ?? [],
                        $submitted,
                        $context['assignableGroupSet']
                    )),
                ]),
            ],
        ], [], 1];
    }

    private function buildDelegatedMountMaps(array $payload, array $context): array
    {
        $dataMap = [];
        foreach ($this->listPayload($payload['groups'] ?? []) as $item) {
            $uid = (int)($item['uid'] ?? 0);
            $group = $this->requireEditableGroup($uid, $context);
            $dataMap['be_groups'][$uid] = $this->filterExistingColumns('be_groups', [
                'db_mountpoints' => $this->csv($this->mergeVisibleInts(
                    $group['dbMountIds'] ?? [],
                    $this->intList($item['dbMounts'] ?? []),
                    $context['pageSet']
                )),
                'file_mountpoints' => $this->csv($this->mergeVisibleInts(
                    $group['fileMountIds'] ?? [],
                    $this->intList($item['fileMounts'] ?? []),
                    $context['fileMountSet']
                )),
            ]);
        }

        return [$dataMap, [], count($dataMap['be_groups'] ?? [])];
    }

    private function tablePermissionLists(array $tableModes): array
    {
        $tablesSelect = [];
        $tablesModify = [];
        foreach ($tableModes as $tableName => $mode) {
            $tableName = trim((string)$tableName);
            if ($tableName === '') {
                continue;
            }
            $mode = (string)$mode;
            if ($mode === 'write') {
                $tablesSelect[] = $tableName;
                $tablesModify[] = $tableName;
                continue;
            }
            if ($mode === 'read') {
                $tablesSelect[] = $tableName;
                continue;
            }
            if ($mode !== 'none') {
                throw new RuntimeException('Ungültiger Tabellenmodus für ' . $tableName . '.');
            }
        }

        return [$this->stringList($tablesSelect), $this->stringList($tablesModify)];
    }

    private function buildContextForScope(string $scope, array $payload): array
    {
        if ($scope === 'group-management' && $this->intList($payload['delete'] ?? []) === []) {
            return [
                'now' => time(),
                'groupsByUid' => [],
                'usersByUid' => [],
                'visibleUsersByUid' => [],
                'editableGroupSet' => [],
                'assignableGroupSet' => [],
                'pageTypeSet' => [],
                'tableSet' => [],
                'moduleSet' => [],
                'pageSet' => [],
                'fileMountSet' => [],
                'isAdmin' => false,
            ];
        }

        return $this->buildContext();
    }

    private function buildContext(): array
    {
        $groups = $this->repository->getGroups();
        $users = $this->repository->getBackendUsers($groups);
        $hasFileMountFeature = ExtensionManagementUtility::isLoaded('filelist');
        $data = [
            'availableFileMounts' => $hasFileMountFeature ? $this->repository->getFileMounts() : [],
            'availableModules' => $this->repository->getBackendModules(),
            'availablePageTypes' => $this->repository->getPageTypes(),
            'availablePages' => $this->repository->getPages(),
            'availableTables' => $this->repository->getTables(),
            'groups' => $this->repository->enrichGroups($groups, $users),
            'hasFileMountFeature' => $hasFileMountFeature,
            'users' => $users,
        ];
        $filtered = $this->accessService->filterWritableViewData($data);

        $assignableGroups = array_values(array_filter(
            $filtered['groups'] ?? [],
            static fn(array $group): bool => (bool)($group['assignable'] ?? false)
        ));
        $editableGroups = array_values(array_filter(
            $filtered['groups'] ?? [],
            static fn(array $group): bool => (bool)($group['editable'] ?? $group['assignable'] ?? false)
        ));

        return [
            'now' => time(),
            'groupsByUid' => $this->mapByUid($groups),
            'usersByUid' => $this->mapByUid($users),
            'visibleUsersByUid' => $this->mapByUid($filtered['users'] ?? []),
            'editableGroupSet' => $this->uidSet($editableGroups),
            'assignableGroupSet' => $this->uidSet($assignableGroups),
            'pageTypeSet' => $this->idSet($this->assignableRows($filtered['availablePageTypes'] ?? [])),
            'tableSet' => $this->idSet($this->assignableRows($filtered['availableTables'] ?? [])),
            'moduleSet' => $this->idSet($this->assignableRows($filtered['availableModules'] ?? [])),
            'pageSet' => $this->uidSet($this->assignableRows($filtered['availablePages'] ?? [])),
            'fileMountSet' => $this->uidSet($this->assignableRows($filtered['availableFileMounts'] ?? [])),
            'isAdmin' => $this->getBackendUser()?->isAdmin() ?? false,
        ];
    }

    private function requireEditableGroup(int $uid, array $context): array
    {
        $group = $context['groupsByUid'][$uid] ?? null;
        if (!is_array($group) || !isset($context['editableGroupSet'][(string)$uid])) {
            throw new RuntimeException('Gruppe UID ' . $uid . ' darf nicht geändert werden.');
        }

        return $group;
    }

    private function canTouchUser(int $uid, array $user, array $context): bool
    {
        if ($context['isAdmin']) {
            return true;
        }
        if ((bool)($user['admin'] ?? false)) {
            return false;
        }

        return isset($context['visibleUsersByUid'][$uid]);
    }

    private function assertGroupHasNoReferences(int $uid, array $context): void
    {
        foreach ($context['usersByUid'] as $user) {
            if (in_array($uid, array_map('intval', $user['groupIds'] ?? []), true)) {
                throw new RuntimeException('Gruppe UID ' . $uid . ' ist noch Backend-Usern zugewiesen.');
            }
        }
        foreach ($context['groupsByUid'] as $group) {
            if ((int)($group['uid'] ?? 0) !== $uid && in_array($uid, array_map('intval', $group['subgroupIds'] ?? []), true)) {
                throw new RuntimeException('Gruppe UID ' . $uid . ' wird noch von einer anderen Gruppe geerbt.');
            }
        }
    }

    private function wouldCreateInheritanceCycle(int $targetUid, array $submittedSubgroups, array $groupMap): bool
    {
        foreach ($submittedSubgroups as $subgroupUid) {
            if ($subgroupUid === $targetUid || $this->inheritsFrom($subgroupUid, $targetUid, $groupMap, [$targetUid => true])) {
                return true;
            }
        }

        return false;
    }

    private function inheritsFrom(int $startUid, int $needleUid, array $groupMap, array $visited = []): bool
    {
        if (isset($visited[$startUid])) {
            return false;
        }
        $visited[$startUid] = true;
        $group = $groupMap[$startUid] ?? null;
        if (!is_array($group)) {
            return false;
        }
        foreach ($group['subgroupIds'] ?? [] as $subgroupUid) {
            $subgroupUid = (int)$subgroupUid;
            if ($subgroupUid === $needleUid || $this->inheritsFrom($subgroupUid, $needleUid, $groupMap, $visited)) {
                return true;
            }
        }

        return false;
    }

    private function mergeVisibleInts(array $current, array $submitted, array $visibleSet): array
    {
        return $this->mergeVisibleValues($this->intList($current), $submitted, $visibleSet, true);
    }

    private function mergeVisibleStrings(array $current, array $submitted, array $visibleSet): array
    {
        return $this->mergeVisibleValues($this->stringList($current), $submitted, $visibleSet, false);
    }

    private function mergeVisibleValues(array $current, array $submitted, array $visibleSet, bool $asInt): array
    {
        $currentSet = array_fill_keys(array_map(static fn(mixed $value): string => (string)$value, $current), true);
        $kept = [];
        foreach ($current as $value) {
            if (!isset($visibleSet[(string)$value])) {
                $kept[] = $value;
            }
        }
        $visible = [];
        foreach ($submitted as $value) {
            $value = $asInt ? (int)$value : (string)$value;
            if (!isset($visibleSet[(string)$value])) {
                if (isset($currentSet[(string)$value])) {
                    continue;
                }
                throw new RuntimeException('Wert ' . $value . ' darf nicht gespeichert werden.');
            }
            $visible[] = $value;
        }

        return $asInt ? $this->intList(array_merge($kept, $visible)) : $this->stringList(array_merge($kept, $visible));
    }

    private function intList(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        $result = [];
        foreach ($items as $item) {
            if ($item === null || $item === '') {
                continue;
            }
            $intValue = (int)$item;
            if ($intValue > 0 && !in_array($intValue, $result, true)) {
                $result[] = $intValue;
            }
        }

        return $result;
    }

    private function stringList(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        $result = [];
        foreach ($items as $item) {
            $stringValue = trim((string)$item);
            if ($stringValue !== '' && !in_array($stringValue, $result, true)) {
                $result[] = $stringValue;
            }
        }

        return $result;
    }

    private function listPayload(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        if ($value === []) {
            return [];
        }

        return array_is_list($value) ? $value : [$value];
    }

    private function mapByUid(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(int)($row['uid'] ?? 0)] = $row;
        }

        return $map;
    }

    private function assignableRows(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => (bool)($row['assignable'] ?? false)
        ));
    }

    private function uidSet(array $rows): array
    {
        $set = [];
        foreach ($rows as $row) {
            $uid = (int)($row['uid'] ?? 0);
            if ($uid > 0) {
                $set[(string)$uid] = true;
            }
        }

        return $set;
    }

    private function idSet(array $rows): array
    {
        $set = [];
        foreach ($rows as $row) {
            $id = trim((string)($row['id'] ?? ''));
            if ($id !== '') {
                $set[$id] = true;
            }
        }

        return $set;
    }

    private function csv(array $values): string
    {
        return implode(',', array_map(static fn(mixed $value): string => (string)$value, $values));
    }

    private function filterExistingColumns(string $tableName, array $fields): array
    {
        try {
            $columns = $this->connectionPool->getConnectionForTable($tableName)->createSchemaManager()->listTableColumns($tableName);
            $columnSet = [];
            foreach ($columns as $column) {
                $columnSet[strtolower($column->getName())] = true;
            }
            if ($columnSet === []) {
                return $fields;
            }

            return array_filter(
                $fields,
                static fn(string $fieldName): bool => isset($columnSet[strtolower($fieldName)]),
                ARRAY_FILTER_USE_KEY
            );
        } catch (\Throwable) {
            return $fields;
        }
    }

    private function cleanText(string $value, int $maxLength): string
    {
        $value = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', strip_tags($value)));

        return substr($value, 0, $maxLength);
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        return ($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication ? $GLOBALS['BE_USER'] : null;
    }
}
