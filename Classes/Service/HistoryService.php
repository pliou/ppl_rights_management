<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

class HistoryService
{
    private const TABLE = 'tx_pplrightsmanagement_history';

    public function __construct(
        private readonly ConnectionPool $connectionPool
    ) {}

    public function getViewData(): array
    {
        $canSeeHistory = $this->canSeeHistory();
        $showImpersonationColumn = $this->canUseBackendUserFastswitch();

        return [
            'canSeeHistory' => $canSeeHistory,
            'historyRows' => $canSeeHistory ? $this->getRows() : [],
            'historyTableReady' => $this->tableReady(),
            'historyNotice' => $showImpersonationColumn
                ? 'History shows rights changes with backend user, time and switch-user source.'
                : 'History shows rights changes with backend user and time.',
            'showImpersonationColumn' => $showImpersonationColumn,
        ];
    }

    public function recordChange(string $scope, array $payload, string $summary, array $history = []): void
    {
        if (!$this->tableReady()) {
            return;
        }

        $backendUser = ($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication ? $GLOBALS['BE_USER'] : null;
        $user = is_array($backendUser?->user ?? null) ? $backendUser->user : [];
        $backendUserUid = (int)($backendUser?->getUserId() ?? ($user['uid'] ?? 0));
        $backendUserName = (string)($backendUser?->getUserName() ?? ($user['username'] ?? ''));
        [$originalBackendUserUid, $originalBackendUserName] = $backendUser instanceof BackendUserAuthentication
            ? $this->getOriginalBackendUserWhenSwitched($backendUser)
            : [0, ''];

        try {
            $now = time();
            $action = $this->describeAction($scope, $payload);
            $summary = $this->describeSummary($scope, $payload, $summary);
            $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
                'pid' => 0,
                'tstamp' => $now,
                'crdate' => $now,
                'backend_user_uid' => $backendUserUid,
                'backend_user_name' => $backendUserName,
                'impersonated_user_uid' => $originalBackendUserUid,
                'impersonated_user_name' => substr($originalBackendUserName, 0, 255),
                'scope' => substr($scope, 0, 80),
                'action' => substr($action, 0, 80),
                'summary' => $summary,
                'payload_before' => $this->encode($history['payloadBefore'] ?? []),
                'payload_after' => $this->encode($history['payloadAfter'] ?? $payload),
            ]);
        } catch (\Throwable) {
        }
    }

    private function getRows(): array
    {
        if (!$this->tableReady()) {
            return [];
        }

        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $rows = $queryBuilder
                ->select('uid', 'tstamp', 'backend_user_name', 'impersonated_user_uid', 'impersonated_user_name', 'scope', 'action', 'summary', 'payload_before', 'payload_after')
                ->from(self::TABLE)
                ->orderBy('tstamp', 'DESC')
                ->setMaxResults(100)
                ->executeQuery()
                ->fetchAllAssociative();

            return array_map(fn(array $row): array => $this->enrichRow($row), $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    private function enrichRow(array $row): array
    {
        $payloadAfter = $this->decode((string)($row['payload_after'] ?? ''));
        if ((string)($row['action'] ?? '') === '' || (string)($row['action'] ?? '') === 'save') {
            $row['action'] = $this->describeAction((string)($row['scope'] ?? ''), $payloadAfter);
        }
        $row['formattedTime'] = $this->formatTimestamp((int)($row['tstamp'] ?? 0));
        $row['impersonated_user_name'] = $this->resolveHistoryBackendUserLabel(
            (int)($row['impersonated_user_uid'] ?? 0),
            (string)($row['impersonated_user_name'] ?? '')
        );
        $row['summary'] = $this->normalizeLegacyText((string)($row['summary'] ?? ''));
        if ($this->isGenericSummary($row['summary'])) {
            $row['summary'] = $this->describeSummary((string)($row['scope'] ?? ''), $payloadAfter, $row['summary']);
        }
        unset($row['payload_before'], $row['payload_after']);

        return $row;
    }

    private function getOriginalBackendUserWhenSwitched(BackendUserAuthentication $backendUser): array
    {
        $originalBackendUserUid = $backendUser->getOriginalUserIdWhenInSwitchUserMode();
        if ($originalBackendUserUid === null || $originalBackendUserUid <= 0) {
            return [0, ''];
        }

        return [$originalBackendUserUid, $this->backendUserLabelByUid($originalBackendUserUid)];
    }

    private function resolveHistoryBackendUserLabel(int $backendUserUid, string $storedName): string
    {
        $storedName = trim($storedName);
        if ($storedName !== '') {
            return $storedName;
        }
        if ($backendUserUid <= 0) {
            return '';
        }

        return $this->backendUserLabelByUid($backendUserUid);
    }

    private function backendUserLabelByUid(int $backendUserUid): string
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
            $row = $queryBuilder
                ->select('uid', 'username', 'realName')
                ->from('be_users')
                ->where(
                    $queryBuilder->expr()->eq(
                        'uid',
                        $queryBuilder->createNamedParameter($backendUserUid, Connection::PARAM_INT)
                    )
                )
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Throwable) {
            $row = false;
        }

        if (!is_array($row)) {
            return 'UID ' . $backendUserUid;
        }

        $username = trim((string)($row['username'] ?? ''));
        $realName = trim((string)($row['realName'] ?? ''));
        if ($username !== '' && $realName !== '') {
            return $username . ' (' . $realName . ', UID ' . $backendUserUid . ')';
        }
        if ($username !== '') {
            return $username . ' (UID ' . $backendUserUid . ')';
        }
        if ($realName !== '') {
            return $realName . ' (UID ' . $backendUserUid . ')';
        }

        return 'UID ' . $backendUserUid;
    }

    private function describeAction(string $scope, array $payload): string
    {
        return match ($scope) {
            'group-management' => $this->describeGroupManagementAction($payload),
            'backend-user-management' => 'Backend user rights changed',
            'group-rights-management' => 'Group rights changed',
            'module-management' => 'Module rights changed',
            'group-rights-inheritance-management' => 'Group inheritance changed',
            'mount-management' => 'Mounts changed',
            default => $scope !== '' ? $scope : 'Change',
        };
    }

    private function describeSummary(string $scope, array $payload, string $fallback): string
    {
        $summary = match ($scope) {
            'group-management' => $this->describeGroupManagementSummary($payload),
            'backend-user-management' => $this->describeStructuredListChange('Backend user rights changed', $payload['users'] ?? [], 'user', [
                'groups' => 'Groups',
                'modules' => 'Module',
                'dbMounts' => 'DB mounts',
                'fileMounts' => 'File mounts',
            ]),
            'group-rights-management' => $this->describeStructuredListChange('Group rights changed', $payload['groups'] ?? [], 'group', [
                'pageTypes' => 'Page types',
                'tables' => 'Tables',
            ]),
            'module-management' => $this->describeStructuredListChange('Module rights changed', $payload['groups'] ?? [], 'group', [
                'modules' => 'Module',
            ]),
            'group-rights-inheritance-management' => 'Group inheritance changed: group UID ' . (int)($payload['groupUid'] ?? 0),
            'mount-management' => $this->describeStructuredListChange('Mounts changed', $payload['groups'] ?? [], 'group', [
                'dbMounts' => 'DB mounts',
                'fileMounts' => 'File mounts',
            ]),
            default => '',
        };

        return $summary !== '' ? $summary : $this->normalizeLegacyText($fallback);
    }

    private function describeGroupManagementAction(array $payload): string
    {
        $creates = $this->listPayload($payload['create'] ?? []);
        $deletes = $this->listPayload($payload['delete'] ?? []);
        if ($creates !== [] && $deletes !== []) {
            return 'Groups changed';
        }
        if ($creates !== []) {
            return count($creates) === 1 ? 'Group created' : 'Groups created';
        }
        if ($deletes !== []) {
            return count($deletes) === 1 ? 'Group deleted' : 'Groups deleted';
        }

        return 'Groups changed';
    }

    private function describeGroupManagementSummary(array $payload): string
    {
        $parts = [];
        $creates = $this->listPayload($payload['create'] ?? []);
        if ($creates !== []) {
            $parts[] = (count($creates) === 1 ? 'Group created: ' : 'Groups created: ')
                . $this->shortList($this->payloadRowLabels($creates));
        }
        $deletes = $this->listPayload($payload['delete'] ?? []);
        if ($deletes !== []) {
            $parts[] = (count($deletes) === 1 ? 'Group deleted: ' : 'Groups deleted: ')
                . $this->shortList(array_map(static fn(mixed $uid): string => 'UID ' . (int)$uid, $deletes));
        }

        return implode('; ', array_filter($parts));
    }

    private function describeListChange(string $prefix, mixed $items, string $unit, array $areas): string
    {
        $items = $this->listPayload($items);
        $count = count($items);
        if ($count === 0) {
            return '';
        }
        $summary = $prefix . ': ' . $count . ' ' . ($count === 1 ? $unit : $unit . 's');
        if ($areas !== []) {
            $summary .= ' (' . implode(', ', $areas) . ')';
        }

        return $summary;
    }

    private function describeStructuredListChange(string $prefix, mixed $items, string $unit, array $fieldLabels): string
    {
        $items = $this->listPayload($items);
        $count = count($items);
        if ($count === 0) {
            return '';
        }
        $details = [];
        foreach (array_slice($items, 0, 3) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $fieldParts = [];
            foreach ($fieldLabels as $field => $label) {
                if (!array_key_exists($field, $item)) {
                    continue;
                }
                $fieldParts[] = $label . ' ' . $this->formatPayloadValue($item[$field]);
            }
            $details[] = $this->labelForPayloadRow($item) . ($fieldParts !== [] ? ': ' . implode('; ', $fieldParts) : '');
        }
        if (count($items) > 3) {
            $details[] = '+' . (count($items) - 3) . ' more';
        }
        $summary = $prefix . ': ' . $count . ' ' . ($count === 1 ? $unit : $unit . 's');
        if ($details !== []) {
            $summary .= ' - ' . implode(' | ', $details);
        }

        return $summary;
    }

    private function formatPayloadValue(mixed $value): string
    {
        if (!is_array($value)) {
            $value = trim((string)$value);

            return $value !== '' ? $value : '-';
        }
        if ($value === []) {
            return '-';
        }
        if (!array_is_list($value)) {
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = (string)$key . '=' . (is_scalar($item) ? (string)$item : json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            return $this->shortList($parts);
        }

        return $this->shortList(array_map(static fn(mixed $item): string => is_scalar($item) ? (string)$item : json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $value));
    }

    private function collectTouchedAreas(mixed $items, array $fieldLabels): array
    {
        $areas = [];
        foreach ($this->listPayload($items) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ($fieldLabels as $field => $label) {
                if (array_key_exists($field, $item)) {
                    $areas[$label] = $label;
                }
            }
        }

        return array_values($areas);
    }

    private function payloadRowLabels(array $rows): array
    {
        $labels = [];
        foreach ($rows as $row) {
            $labels[] = is_array($row) ? $this->labelForPayloadRow($row) : (string)$row;
        }

        return $labels;
    }

    private function labelForPayloadRow(array $row): string
    {
        $title = trim((string)($row['title'] ?? $row['username'] ?? $row['name'] ?? ''));
        if ($title !== '') {
            return $title;
        }
        $uid = (int)($row['uid'] ?? 0);

        return $uid > 0 ? 'UID ' . $uid : 'new record';
    }

    private function shortList(array $values): string
    {
        $values = array_values(array_filter(array_map('strval', $values), static fn(string $value): bool => trim($value) !== ''));
        if ($values === []) {
            return '';
        }
        $visible = array_slice($values, 0, 5);
        $suffix = count($values) > 5 ? ' +' . (count($values) - 5) . ' more' : '';

        return implode(', ', $visible) . $suffix;
    }

    private function isGenericSummary(string $summary): bool
    {
        return (bool)preg_match('/^\d+\s+change(?:s)?\s+saved\.$/u', trim($summary))
            || (bool)preg_match('/^\d+\s+change(?:s)?\s+stored\.$/u', trim($summary));
    }

    private function listPayload(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            return [];
        }

        return array_is_list($value) ? $value : [$value];
    }

    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeLegacyText(string $value): string
    {
        return $value;
    }

    private function formatTimestamp(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }
        $language = $this->getBackendLanguage();
        $format = str_starts_with($language, 'de') ? 'd.m.Y H:i' : 'Y-m-d H:i';

        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone($this->getBackendTimezone())
            ->format($format);
    }

    private function getBackendTimezone(): \DateTimeZone
    {
        $backendUser = ($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication ? $GLOBALS['BE_USER'] : null;
        $user = is_array($backendUser?->user ?? null) ? $backendUser->user : [];
        $uc = is_array($backendUser?->uc ?? null) ? $backendUser->uc : [];
        $candidates = [
            $uc['timezone'] ?? null,
            $uc['timeZone'] ?? null,
            $user['timezone'] ?? null,
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['timezone'] ?? null,
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['defaultTimeZone'] ?? null,
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['phpTimeZone'] ?? null,
            'Europe/Berlin',
            date_default_timezone_get(),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '') {
                continue;
            }
            try {
                return new \DateTimeZone($candidate);
            } catch (\Throwable) {
            }
        }

        return new \DateTimeZone('UTC');
    }

    private function tableReady(): bool
    {
        try {
            $this->connectionPool->getConnectionForTable(self::TABLE)->createSchemaManager()->listTableColumns(self::TABLE);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function canSeeHistory(): bool
    {
        $backendUser = ($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication ? $GLOBALS['BE_USER'] : null;

        return $backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin();
    }

    private function canUseBackendUserFastswitch(): bool
    {
        if (!ExtensionManagementUtility::isLoaded('beuser_fastswitch')) {
            return false;
        }
        $backendUser = ($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication ? $GLOBALS['BE_USER'] : null;
        if (!$backendUser instanceof BackendUserAuthentication || !$backendUser->isAdmin()) {
            return false;
        }
        if ((int)($backendUser->getOriginalUserIdWhenInSwitchUserMode() ?? 0) !== 0) {
            return false;
        }

        try {
            $disabled = $backendUser->getTSConfig()['options.']['backendToolbarItem.']['beUserFastwitch.']['disabled'] ?? false;
        } catch (\Throwable) {
            $disabled = false;
        }

        return (int)$disabled !== 1;
    }

    private function getBackendLanguage(): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (is_object($languageService) && method_exists($languageService, 'getLocale')) {
            $locale = strtolower((string)$languageService->getLocale());
            if ($locale !== '') {
                return $locale;
            }
        }
        $backendUser = ($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication ? $GLOBALS['BE_USER'] : null;
        $user = is_array($backendUser?->user ?? null) ? $backendUser->user : [];
        $uc = is_array($backendUser?->uc ?? null) ? $backendUser->uc : [];

        return strtolower((string)($uc['lang'] ?? $user['lang'] ?? 'en'));
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }
}
