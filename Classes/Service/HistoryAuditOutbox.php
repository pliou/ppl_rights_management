<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Service;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Durable, crash-safe, idempotent file outbox for rights-management audit (history) rows.
 *
 * A privileged rights change always succeeds first; if its audit row cannot be written at that
 * moment it is parked here instead of being silently lost, and replayed into the history table on
 * the next flush (CLI command or when an admin opens the History tab).
 *
 * Crash-safety / correctness properties:
 *  - lives under var/ (NOT var/transient/, which a cache flush may wipe);
 *  - every file is written atomically (temp file + rename) with JSON_THROW_ON_ERROR + a checked
 *    write, so a crash never leaves a truncated entry;
 *  - flush() claims each file with an atomic rename to a ".processing" name, so two parallel flush
 *    runs can never import the same entry twice;
 *  - replay is idempotent via the immutable event_id (a crash after the DB insert but before the
 *    file delete does NOT create a duplicate on the next run — the existing row is detected);
 *  - the undo->original revert linkage is re-applied idempotently;
 *  - unreadable/corrupt files are moved to a quarantine/ folder and logged, never left to spin.
 */
final class HistoryAuditOutbox
{
    private const TABLE = 'tx_pplrightsmanagement_history';
    private const SUBDIR = 'ppl_rights_management/audit_outbox/';
    private const QUARANTINE_SUBDIR = 'ppl_rights_management/audit_outbox/quarantine/';
    private const PROCESSING_SUFFIX = '.processing';
    private const STALE_PROCESSING_SECONDS = 600;

    public function __construct(
        private readonly ConnectionPool $connectionPool
    ) {}

    /**
     * @param array<string, mixed> $row a full history-table row (insert payload, incl. event_id)
     */
    public function store(array $row): void
    {
        try {
            $json = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $file = $this->directory() . date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.json';
            $this->atomicWrite($file, $json);
        } catch (\Throwable $exception) {
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(__CLASS__)
                ->error('Could not persist a rights-management audit entry to the outbox.', ['exception' => $exception]);
        }
    }

    /**
     * Replay queued audit rows into the history table. Returns the number newly recovered. Stops on
     * the first DB error so a still-unhealthy database simply leaves the queue for the next attempt.
     */
    public function flush(): int
    {
        if (!$this->tableReady()) {
            return 0;
        }
        $this->reclaimStaleProcessingFiles();

        $recovered = 0;
        foreach ($this->files() as $file) {
            $claimed = $this->claim($file);
            if ($claimed === null) {
                // Another flush won the claim, or the file vanished.
                continue;
            }
            $row = $this->decode($claimed);
            if ($row === null) {
                $this->quarantine($claimed);
                continue;
            }
            try {
                if ($this->replay($row)) {
                    $recovered++;
                }
                @unlink($claimed);
            } catch (\Throwable) {
                // Database still unavailable: release the claim and stop; retried on the next flush.
                @rename($claimed, $file);
                break;
            }
        }

        return $recovered;
    }

    public function pendingCount(): int
    {
        return count($this->files());
    }

    /**
     * Insert one queued row idempotently (keyed by event_id) and re-apply its revert linkage.
     *
     * @param array<string, mixed> $row
     * @return bool true if a new row was inserted, false if it already existed (dedup)
     */
    private function replay(array $row): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $eventId = (string)($row['event_id'] ?? '');
        $revertsUid = (int)($row['reverts_history_uid'] ?? 0);
        $tstamp = (int)($row['tstamp'] ?? 0);

        $existingUid = $eventId !== '' ? $this->findByEventId($eventId) : 0;
        if ($existingUid > 0) {
            // Already recorded (crash after insert before unlink, or a racing flush): only make sure
            // a pending revert link is applied, then treat the file as done.
            if ($revertsUid > 0) {
                $this->applyRevertLink($connection, $revertsUid, $existingUid, $tstamp);
            }
            return false;
        }

        $connection->beginTransaction();
        try {
            $connection->insert(self::TABLE, $row);
            $newUid = (int)$connection->lastInsertId();
            if ($revertsUid > 0 && $newUid > 0) {
                $this->applyRevertLink($connection, $revertsUid, $newUid, $tstamp);
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            try {
                $connection->rollBack();
            } catch (\Throwable) {
                // nothing else we can do; rethrow the original cause below
            }
            throw $exception;
        }

        return true;
    }

    private function applyRevertLink(Connection $connection, int $originalUid, int $undoUid, int $tstamp): void
    {
        $connection->update(
            self::TABLE,
            ['reverted_by_history_uid' => $undoUid, 'reverted_at' => $tstamp > 0 ? $tstamp : time()],
            ['uid' => $originalUid]
        );
    }

    private function findByEventId(string $eventId): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return (int)$queryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('event_id', $queryBuilder->createNamedParameter($eventId)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Atomically take ownership of a queued file by renaming it to a ".processing" sibling.
     * Returns the new path, or null if the claim failed (already taken / gone).
     */
    private function claim(string $file): ?string
    {
        $target = $file . self::PROCESSING_SUFFIX;

        return @rename($file, $target) ? $target : null;
    }

    /**
     * Return ".processing" files left behind by a crashed flush to the queue so they get retried.
     */
    private function reclaimStaleProcessingFiles(): void
    {
        $dir = $this->baseDirectory();
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '*' . self::PROCESSING_SUFFIX) ?: [] as $processing) {
            $mtime = @filemtime($processing);
            if ($mtime !== false && (time() - $mtime) > self::STALE_PROCESSING_SECONDS) {
                @rename($processing, substr($processing, 0, -strlen(self::PROCESSING_SUFFIX)));
            }
        }
    }

    private function quarantine(string $file): void
    {
        try {
            $target = $this->quarantineDirectory() . basename($file) . '.' . date('Ymd-His');
            if (!@rename($file, $target)) {
                @unlink($file);
            }
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(__CLASS__)
                ->error('Quarantined an unreadable rights-management audit outbox file.', ['file' => basename($file)]);
        } catch (\Throwable) {
            @unlink($file);
        }
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temp, $contents) === false) {
            throw new \RuntimeException('Could not write the audit outbox temp file: ' . $temp);
        }
        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Could not move the audit outbox temp file into place: ' . $path);
        }
    }

    /**
     * @return string[]
     */
    private function files(): array
    {
        $dir = $this->baseDirectory();

        return is_dir($dir) ? (glob($dir . '*.json') ?: []) : [];
    }

    private function baseDirectory(): string
    {
        return rtrim(str_replace('\\', '/', Environment::getVarPath()), '/') . '/' . self::SUBDIR;
    }

    private function directory(): string
    {
        $dir = $this->baseDirectory();
        if (!is_dir($dir)) {
            GeneralUtility::mkdir_deep($dir);
        }

        return $dir;
    }

    private function quarantineDirectory(): string
    {
        $dir = rtrim(str_replace('\\', '/', Environment::getVarPath()), '/') . '/' . self::QUARANTINE_SUBDIR;
        if (!is_dir($dir)) {
            GeneralUtility::mkdir_deep($dir);
        }

        return $dir;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $file): ?array
    {
        $decoded = json_decode((string)file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
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
}
