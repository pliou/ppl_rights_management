<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\Event\AfterGroupsResolvedEvent;
use TYPO3\CMS\Core\Utility\StringUtility;

#[AsEventListener(identifier: 'ppl-rights-management/backend-group-module-access')]
final class BackendGroupModuleAccessListener
{
    private const MODULE_IDS = [
        'ppl_rights_management',
    ];

    public function __invoke(AfterGroupsResolvedEvent $event): void
    {
        if ($event->getSourceDatabaseTable() !== 'be_groups') {
            return;
        }

        $allowedGroups = $this->getStringListConfiguration('moduleAccessGroupIds');
        if ($allowedGroups === []) {
            return;
        }

        $groups = $event->getGroups();
        $changed = false;
        foreach ($groups as $index => $group) {
            if (!$this->matchesAllowedGroup($allowedGroups, $group)) {
                continue;
            }

            $groups[$index]['groupMods'] = StringUtility::uniqueList(implode(',', [
                (string)($group['groupMods'] ?? ''),
                ...self::MODULE_IDS,
            ]));
            $changed = true;
        }

        if ($changed) {
            $event->setGroups($groups);
        }
    }

    private function matchesAllowedGroup(array $allowedGroups, array $group): bool
    {
        $groupUid = (int)($group['uid'] ?? 0);
        $groupTitle = strtolower(trim((string)($group['title'] ?? '')));

        foreach ($allowedGroups as $allowedGroup) {
            if (ctype_digit($allowedGroup) && (int)$allowedGroup === $groupUid) {
                return true;
            }
            if (!ctype_digit($allowedGroup) && strtolower($allowedGroup) === $groupTitle) {
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
        ];
        $pplConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ppl_rights_management'] ?? [];
        $pplConfiguration = is_array($pplConfiguration) ? $pplConfiguration : [];

        return array_replace($defaults, $pplConfiguration);
    }
}
