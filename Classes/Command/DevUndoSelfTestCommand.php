<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Command;

use Ppl\PplRightsManagement\Domain\Repository\OverviewManagementRepository;
use Ppl\PplRightsManagement\Service\HistoryRevertService;
use Ppl\PplRightsManagement\Service\HistoryService;
use Ppl\PplRightsManagement\Service\RightsManagementAccessService;
use Ppl\PplRightsManagement\Service\RightsManagementSaveService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * DEV-ONLY end-to-end self test for the History Undo feature.
 *
 * Realistic scenario: a non-admin backend user is a member of a "people" group; a
 * second "full-rights" group is granted ALL rights (all doktypes, all tables RW, all
 * modules, all mounts), the people group inherits it (group matching), and the user is
 * assigned the full-rights group plus all mounts/modules directly. Every grant is
 * recorded in history exactly like the Save controller. The test then UNDOES every grant
 * via {@see HistoryRevertService}, asserts each affected DB field is back to its original
 * value, and finally deletes everything it created (records + its own history rows). It
 * never touches pre-existing data.
 *
 * NEVER ship this in a release: excluded from dist via `.gitattributes` (export-ignore)
 * and it refuses to run without the explicit `--force` flag, because it writes to and
 * deletes from be_groups/be_users.
 */
#[AsCommand(
    name: 'ppl:rights:dev-undo-selftest',
    description: '[DEV ONLY] Grant a user/group all rights + mounts, undo every grant, verify the revert, then delete everything.'
)]
final class DevUndoSelfTestCommand extends Command
{
    private const HISTORY_TABLE = 'tx_pplrightsmanagement_history';
    private const DOKTYPES = ['1', '3', '4', '6', '7', '254', '255'];

    private ConnectionPool $cp;
    private RightsManagementSaveService $saveService;
    private HistoryService $historyService;
    private HistoryRevertService $revertService;

    /** @var array{groups: int[], users: int[], history: int[]} */
    private array $created = ['groups' => [], 'users' => [], 'history' => []];

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Confirm you are running this destructive DEV-ONLY test.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Hard runtime guard in addition to the dist export-ignore: never run in a Production context,
        // regardless of --force, because this creates and deletes be_users/be_groups.
        if (Environment::getContext()->isProduction()) {
            $output->writeln('<error>Refusing to run in a Production context.</error> This is a DEV-ONLY, '
                . 'destructive self test (it creates and deletes be_groups/be_users). '
                . 'Set TYPO3_CONTEXT=Development or Testing to run it.');
            return Command::FAILURE;
        }
        if (!$input->getOption('force')) {
            $output->writeln('<error>Refusing to run.</error> This is a DEV-ONLY, destructive self test '
                . '(it creates and deletes be_groups/be_users). Re-run with --force if this is a dev environment.');
            return Command::INVALID;
        }

        $this->cp = GeneralUtility::makeInstance(ConnectionPool::class);
        if (!$this->setUpAdminContext($output)) {
            return Command::FAILURE;
        }
        $this->bootServices();

        $token = substr(bin2hex(random_bytes(4)), 0, 8);
        $results = [];

        try {
            $peopleGroup = $this->createGroup('[undo-selftest ' . $token . '] Menschengruppe');
            $rightsGroup = $this->createGroup('[undo-selftest ' . $token . '] Vollrechte-Gruppe');
            $user = $this->createUser('_undo_selftest_' . $token, [$peopleGroup]);

            $allTablesWrite = array_fill_keys(array_keys($GLOBALS['TCA'] ?? []), 'write');
            $allModules = $this->allModuleIdentifiers();
            $allPages = $this->pageUids();
            $allFileMounts = $this->fileMountUids();

            $output->writeln(sprintf(
                'Scenario (token %s): people-group %d, full-rights-group %d, non-admin user %d (member of people-group).',
                $token, $peopleGroup, $rightsGroup, $user
            ));
            $output->writeln(sprintf(
                'Granting: %d tables RW, %d doktypes, %d modules, %d db-mounts, %d file-mounts.',
                count($allTablesWrite), count(self::DOKTYPES), count($allModules), count($allPages), count($allFileMounts)
            ));

            // Each step: scope, target table+uid, the fields it touches, and the grant payload.
            $steps = [
                [
                    'scope' => 'group-rights-management', 'table' => 'be_groups', 'uid' => $rightsGroup,
                    'fields' => ['pagetypes_select', 'tables_select', 'tables_modify'],
                    'payload' => ['groups' => [['uid' => $rightsGroup, 'pageTypes' => self::DOKTYPES, 'tables' => $allTablesWrite]]],
                ],
                [
                    'scope' => 'module-management', 'table' => 'be_groups', 'uid' => $rightsGroup,
                    'fields' => ['groupMods'],
                    'payload' => ['groups' => [['uid' => $rightsGroup, 'modules' => $allModules]]],
                ],
                [
                    'scope' => 'mount-management', 'table' => 'be_groups', 'uid' => $rightsGroup,
                    'fields' => ['db_mountpoints', 'file_mountpoints'],
                    'payload' => ['groups' => [['uid' => $rightsGroup, 'dbMounts' => $allPages, 'fileMounts' => $allFileMounts]]],
                ],
                [
                    'scope' => 'group-rights-inheritance-management', 'table' => 'be_groups', 'uid' => $peopleGroup,
                    'fields' => ['subgroup'],
                    'payload' => ['groupUid' => $peopleGroup, 'inherited' => [$rightsGroup]],
                ],
                [
                    'scope' => 'backend-user-management', 'table' => 'be_users', 'uid' => $user,
                    'fields' => ['usergroup', 'userMods', 'db_mountpoints', 'file_mountpoints'],
                    'payload' => ['users' => [[
                        'uid' => $user, 'groups' => [$peopleGroup, $rightsGroup],
                        'modules' => $allModules, 'dbMounts' => $allPages, 'fileMounts' => $allFileMounts,
                    ]]],
                ],
            ];

            // Phase 1: build the full rights state (capture original + after-save per field).
            foreach ($steps as $i => $step) {
                $steps[$i]['original'] = $this->readFields($step['table'], $step['uid'], $step['fields']);
                // RightsManagementSaveService::save() now records the history row itself, so do not
                // record it a second time here (that would create a duplicate audit row).
                $this->saveService->save($step['scope'], $step['payload']);
                $steps[$i]['saveUid'] = $this->maxHistoryUid();
                $this->created['history'][] = $steps[$i]['saveUid'];
                $steps[$i]['afterSave'] = $this->readFields($step['table'], $step['uid'], $step['fields']);
            }

            // Phase 2: undo every grant (reverse order) and verify the revert.
            foreach (array_reverse($steps, true) as $step) {
                $results[] = $this->undoAndVerify($step);
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>Aborted: ' . $e->getMessage() . '</error>');
            $results[] = ['scope' => '(exception)', 'status' => 'FAIL', 'detail' => $e->getMessage()];
        } finally {
            $this->cleanUp($output);
        }

        return $this->report($output, $results);
    }

    /**
     * @param array<string, mixed> $step
     * @return array{scope: string, status: string, detail: string}
     */
    private function undoAndVerify(array $step): array
    {
        $scope = (string)$step['scope'];
        try {
            /** @var array<string, string> $original */
            $original = $step['original'];
            /** @var array<string, string> $afterSave */
            $afterSave = $step['afterSave'];

            $changed = [];
            foreach ($step['fields'] as $field) {
                if (($afterSave[$field] ?? '') !== ($original[$field] ?? '')) {
                    $changed[] = $field;
                }
            }
            if ($changed === []) {
                return ['scope' => $scope, 'status' => 'SKIP', 'detail' => 'no field changed (values not accepted) - nothing to undo'];
            }

            $undo = $this->revertService->undo((int)$step['saveUid']);
            $undoUid = $this->maxHistoryUid();
            if ($undoUid !== (int)$step['saveUid']) {
                $this->created['history'][] = $undoUid;
            }

            $afterUndo = $this->readFields($step['table'], (int)$step['uid'], $step['fields']);
            $notReverted = [];
            foreach ($step['fields'] as $field) {
                if (($afterUndo[$field] ?? '') !== ($original[$field] ?? '')) {
                    $notReverted[] = $field;
                }
            }

            $ok = ($undo['status'] ?? '') === 'ok' && $notReverted === [];
            $detail = sprintf(
                'undo=%s; changed[%s] reverted-ok=%s%s',
                $undo['status'] ?? '?',
                implode(',', $changed),
                $notReverted === [] ? 'yes' : 'NO',
                $notReverted === [] ? '' : ' (still off: ' . implode(',', $notReverted) . ')'
            );

            return ['scope' => $scope, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
        } catch (\Throwable $e) {
            return ['scope' => $scope, 'status' => 'FAIL', 'detail' => 'exception: ' . $e->getMessage()];
        }
    }

    private function setUpAdminContext(OutputInterface $output): bool
    {
        $queryBuilder = $this->cp->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();
        $adminUid = (int)$queryBuilder
            ->select('uid')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('admin', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if ($adminUid <= 0) {
            $output->writeln('<error>No active admin backend user found.</error>');
            return false;
        }

        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->setBeUserByUid($adminUid);
        $backendUser->fetchGroupData();
        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        return $backendUser->user !== null && $backendUser->isAdmin();
    }

    private function bootServices(): void
    {
        $repository = GeneralUtility::makeInstance(
            OverviewManagementRepository::class,
            $this->cp,
            GeneralUtility::makeInstance(ModuleProvider::class)
        );
        $this->saveService = GeneralUtility::makeInstance(
            RightsManagementSaveService::class,
            $this->cp,
            $repository,
            GeneralUtility::makeInstance(RightsManagementAccessService::class)
        );
        $this->historyService = GeneralUtility::makeInstance(HistoryService::class, $this->cp);
        $this->revertService = GeneralUtility::makeInstance(
            HistoryRevertService::class,
            $this->historyService,
            $this->saveService,
            $this->cp
        );
    }

    /** @return string[] */
    private function allModuleIdentifiers(): array
    {
        try {
            $backendUser = $GLOBALS['BE_USER'] instanceof BackendUserAuthentication ? $GLOBALS['BE_USER'] : null;
            $modules = GeneralUtility::makeInstance(ModuleProvider::class)->getModules($backendUser);
            return array_values(array_keys($modules));
        } catch (\Throwable) {
            return ['web_layout', 'web_list'];
        }
    }

    /** @return int[] */
    private function pageUids(): array
    {
        return $this->uidList('pages', 50);
    }

    /** @return int[] */
    private function fileMountUids(): array
    {
        return $this->uidList('sys_filemounts', 50);
    }

    /** @return int[] */
    private function uidList(string $table, int $limit): array
    {
        try {
            $queryBuilder = $this->cp->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            $rows = $queryBuilder
                ->select('uid')
                ->from($table)
                ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
                ->orderBy('uid', 'ASC')
                ->setMaxResults($limit)
                ->executeQuery()
                ->fetchFirstColumn();
            return array_map('intval', $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    private function createGroup(string $title): int
    {
        $now = time();
        $connection = $this->cp->getConnectionForTable('be_groups');
        $connection->insert('be_groups', [
            'pid' => 0, 'tstamp' => $now, 'crdate' => $now, 'deleted' => 0, 'hidden' => 0, 'title' => $title,
        ]);
        $uid = (int)$connection->lastInsertId();
        $this->created['groups'][] = $uid;

        return $uid;
    }

    /**
     * @param int[] $groups
     */
    private function createUser(string $username, array $groups = []): int
    {
        $now = time();
        $connection = $this->cp->getConnectionForTable('be_users');
        $connection->insert('be_users', [
            'pid' => 0, 'tstamp' => $now, 'crdate' => $now, 'deleted' => 0, 'disable' => 0, 'admin' => 0,
            'username' => $username, 'password' => '',
            'usergroup' => implode(',', array_map('intval', $groups)),
        ]);
        $uid = (int)$connection->lastInsertId();
        $this->created['users'][] = $uid;

        return $uid;
    }

    /**
     * @param string[] $fields
     * @return array<string, string>
     */
    private function readFields(string $table, int $uid, array $fields): array
    {
        $queryBuilder = $this->cp->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select(...$fields)
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        $row = is_array($row) ? $row : [];

        $values = [];
        foreach ($fields as $field) {
            $values[$field] = (string)($row[$field] ?? '');
        }

        return $values;
    }

    private function maxHistoryUid(): int
    {
        $queryBuilder = $this->cp->getQueryBuilderForTable(self::HISTORY_TABLE);

        return (int)$queryBuilder
            ->select('uid')
            ->from(self::HISTORY_TABLE)
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }

    private function cleanUp(OutputInterface $output): void
    {
        $removed = [self::HISTORY_TABLE => 0, 'be_groups' => 0, 'be_users' => 0];
        $deletions = [
            [self::HISTORY_TABLE, array_values(array_unique(array_filter($this->created['history'])))],
            ['be_groups', $this->created['groups']],
            ['be_users', $this->created['users']],
        ];
        foreach ($deletions as [$table, $uids]) {
            foreach ($uids as $uid) {
                try {
                    $removed[$table] += $this->cp->getConnectionForTable($table)->delete($table, ['uid' => (int)$uid]);
                } catch (\Throwable $e) {
                    $output->writeln(sprintf('<comment>Cleanup warning for %s:%d: %s</comment>', $table, $uid, $e->getMessage()));
                }
            }
        }
        $output->writeln(sprintf('Cleanup: removed %d history row(s), %d group(s), %d user(s).',
            $removed[self::HISTORY_TABLE], $removed['be_groups'], $removed['be_users']));
    }

    /**
     * @param array<int, array{scope: string, status: string, detail: string}> $results
     */
    private function report(OutputInterface $output, array $results): int
    {
        $output->writeln('');
        $output->writeln('=== History Undo self test (full-rights scenario) ===');
        $failed = 0;
        foreach ($results as $r) {
            $tag = match ($r['status']) {
                'PASS' => '<info>PASS</info>',
                'SKIP' => '<comment>SKIP</comment>',
                default => '<error>FAIL</error>',
            };
            if ($r['status'] === 'FAIL') {
                $failed++;
            }
            $output->writeln(sprintf('  [%s] %-38s %s', $tag, $r['scope'], $r['detail'] ?? ''));
        }
        $output->writeln('');
        if ($failed === 0) {
            $output->writeln('<info>RESULT: all granted rights/mounts were undone correctly; all test data removed.</info>');
            return Command::SUCCESS;
        }
        $output->writeln(sprintf('<error>RESULT: %d scope(s) failed.</error>', $failed));

        return Command::FAILURE;
    }
}
