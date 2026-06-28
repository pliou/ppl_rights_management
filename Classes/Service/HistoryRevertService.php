<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * History undo (v1): reverses a recorded rights change by writing the stored
 * before-state back through the same per-scope allowlist and privileged DataHandler
 * writer as a forward save.
 *
 * Safety model:
 * - admin-only;
 * - only update-only scopes (no create/delete) - see {@see HistoryService::isUndoableScope()};
 * - field-level conflict check: a field is only reverted when the current DB value
 *   still equals the recorded after-value, so a later change by someone else is never
 *   silently overwritten;
 * - the undo itself is recorded as a new history row and the original is marked reverted.
 */
final class HistoryRevertService
{
    private const SUPPORTED_TABLES = [
        'be_users' => true,
        'be_groups' => true,
    ];

    public function __construct(
        private readonly HistoryService $historyService,
        private readonly RightsManagementSaveService $saveService,
        private readonly ConnectionPool $connectionPool
    ) {}

    /**
     * @return array{status: string, message: string}
     */
    public function undo(int $historyUid): array
    {
        $backendUser = ($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication ? $GLOBALS['BE_USER'] : null;
        if (!$backendUser instanceof BackendUserAuthentication || !$backendUser->isAdmin()) {
            return ['status' => 'error', 'message' => 'Undo requires administrator privileges.'];
        }

        $row = $this->historyService->getRow($historyUid);
        if ($row === []) {
            return ['status' => 'error', 'message' => 'History entry not found.'];
        }

        $scope = (string)($row['scope'] ?? '');
        if (!$this->historyService->isUndoableScope($scope)) {
            return ['status' => 'error', 'message' => 'This type of change cannot be undone yet.'];
        }
        if ((int)($row['reverted_by_history_uid'] ?? 0) > 0) {
            return ['status' => 'error', 'message' => 'This change has already been undone.'];
        }
        if ((int)($row['reverts_history_uid'] ?? 0) > 0) {
            return ['status' => 'error', 'message' => 'An undo entry cannot itself be undone.'];
        }

        $before = $this->decode((string)($row['payload_before'] ?? ''));
        $after = $this->decode((string)($row['payload_after'] ?? ''));
        if ($after === []) {
            return ['status' => 'error', 'message' => 'This change has no comparable state and cannot be undone.'];
        }

        // Serialize on the affected records so the field-level conflict check and the write form one
        // critical section (no TOCTOU overwrite of a concurrent change), and the same history entry
        // cannot be undone twice at once.
        $locks = $this->acquireRecordLocks($this->affectedRecordKeys($after));
        try {
            return $this->performUndoLocked($historyUid, $scope, $before, $after);
        } finally {
            $this->releaseRecordLocks($locks);
        }
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array{status: string, message: string}
     */
    private function performUndoLocked(int $historyUid, string $scope, array $before, array $after): array
    {
        // Re-check under the lock: the entry must not have been reverted between the first read and now.
        $freshRow = $this->historyService->getRow($historyUid);
        if ($freshRow === [] || (int)($freshRow['reverted_by_history_uid'] ?? 0) > 0) {
            return ['status' => 'error', 'message' => 'This change has already been undone.'];
        }

        $dataMap = [];
        $conflicts = [];
        foreach ($after as $table => $records) {
            $table = (string)$table;
            if (!isset(self::SUPPORTED_TABLES[$table]) || !is_array($records)) {
                $conflicts[] = $table;
                continue;
            }
            foreach ($records as $uid => $afterFields) {
                $uid = (int)$uid;
                if ($uid <= 0 || !is_array($afterFields)) {
                    continue;
                }
                $beforeFields = is_array($before[$table][$uid] ?? null) ? $before[$table][$uid] : [];
                $current = $this->fetchCurrent($table, $uid);
                if ($current === []) {
                    $conflicts[] = $table . ':' . $uid . ' (record no longer exists)';
                    continue;
                }

                $inverse = [];
                $hasConflict = false;
                foreach ($afterFields as $field => $afterValue) {
                    $field = (string)$field;
                    if ($field === 'uid') {
                        continue;
                    }
                    if (!$this->valuesEqual($current[$field] ?? null, $afterValue)) {
                        $conflicts[] = $table . ':' . $uid . '.' . $field;
                        $hasConflict = true;
                        break;
                    }
                    if (array_key_exists($field, $beforeFields)) {
                        $inverse[$field] = $beforeFields[$field];
                    }
                }
                if ($hasConflict) {
                    continue;
                }
                if ($inverse !== []) {
                    $dataMap[$table][$uid] = $inverse;
                }
            }
        }

        if ($conflicts !== []) {
            return [
                'status' => 'conflict',
                'message' => 'This change cannot be undone automatically because the data was changed afterwards: '
                    . implode(', ', array_slice($conflicts, 0, 6)) . '.',
            ];
        }
        if ($dataMap === []) {
            return ['status' => 'error', 'message' => 'There is nothing to undo for this change.'];
        }

        $count = $this->saveService->applyVettedUndo($scope, $dataMap);
        if ($count === 0) {
            return ['status' => 'error', 'message' => 'The undo did not change anything.'];
        }

        $undoUid = $this->historyService->recordRevert(
            $historyUid,
            $scope,
            $after,
            $dataMap,
            'Reverted change #' . $historyUid . '.'
        );

        return [
            'status' => 'ok',
            'message' => 'Change #' . $historyUid . ' was undone'
                . ($undoUid > 0 ? ' and recorded as history #' . $undoUid : '') . '.',
        ];
    }

    /**
     * Distinct "table:uid" keys of the records this undo would touch, sorted for a deterministic lock
     * acquisition order (so two concurrent undos can never deadlock).
     *
     * @param array<string, mixed> $after
     * @return string[]
     */
    private function affectedRecordKeys(array $after): array
    {
        $keys = [];
        foreach ($after as $table => $records) {
            $table = (string)$table;
            if (!isset(self::SUPPORTED_TABLES[$table]) || !is_array($records)) {
                continue;
            }
            foreach (array_keys($records) as $uid) {
                $uid = (int)$uid;
                if ($uid > 0) {
                    $keys[$table . ':' . $uid] = true;
                }
            }
        }
        $keys = array_keys($keys);
        sort($keys);

        return $keys;
    }

    /**
     * @param string[] $recordKeys
     * @return LockingStrategyInterface[]
     */
    private function acquireRecordLocks(array $recordKeys): array
    {
        $locks = [];
        foreach ($recordKeys as $key) {
            try {
                $lock = GeneralUtility::makeInstance(LockFactory::class)
                    ->createLocker('ppl_rights_rec_' . str_replace(':', '_', $key));
                $lock->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);
                $locks[] = $lock;
            } catch (\Throwable) {
                // Locking unavailable on this platform: proceed (the field-level conflict check still guards).
            }
        }

        return $locks;
    }

    /**
     * @param LockingStrategyInterface[] $locks
     */
    private function releaseRecordLocks(array $locks): void
    {
        foreach (array_reverse($locks) as $lock) {
            if ($lock instanceof LockingStrategyInterface) {
                try {
                    $lock->release();
                } catch (\Throwable) {
                    // Best effort; the lock is also released when the process ends.
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchCurrent(string $table, int $uid): array
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            $row = $queryBuilder
                ->select('*')
                ->from($table)
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER)))
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Throwable) {
            return [];
        }

        return is_array($row) ? $row : [];
    }

    private function valuesEqual(mixed $a, mixed $b): bool
    {
        return $this->normalizeScalar($a) === $this->normalizeScalar($b);
    }

    private function normalizeScalar(mixed $value): string
    {
        if (is_array($value)) {
            return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return trim((string)($value ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
