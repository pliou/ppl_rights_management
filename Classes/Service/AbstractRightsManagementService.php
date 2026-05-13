<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Service;

use Ppl\PplRightsManagement\Domain\Repository\AbstractRightsManagementRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

abstract class AbstractRightsManagementService
{
    public function __construct(
        protected readonly AbstractRightsManagementRepository $repository
    ) {}

    public function getViewData(): array
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

        return GeneralUtility::makeInstance(RightsManagementAccessService::class)->filterViewData($data);
    }

    protected function getSelectedGroups(array $groups): array
    {
        $selectedGroupIds = $this->getSelectedGroupIds();
        if ($selectedGroupIds === []) {
            return array_slice($groups, 0, 5);
        }

        $selectedGroups = array_values(array_filter(
            $groups,
            static fn(array $group): bool => in_array($group['uid'], $selectedGroupIds, true)
        ));

        return array_slice($selectedGroups, 0, 10);
    }

    protected function markSelectedGroups(array $groups, array $selectedGroups): array
    {
        $selectedGroupIds = array_map(static fn(array $group): int => (int)$group['uid'], $selectedGroups);
        foreach ($groups as $index => $group) {
            $groups[$index]['selected'] = in_array((int)$group['uid'], $selectedGroupIds, true);
        }

        return $groups;
    }

    protected function getSelectedGroupIds(): array
    {
        if (!isset($GLOBALS['TYPO3_REQUEST'])) {
            return [];
        }

        $groups = $GLOBALS['TYPO3_REQUEST']->getQueryParams()['groups'] ?? [];
        if (!is_array($groups)) {
            $groups = [$groups];
        }

        return array_values(array_filter(array_map('intval', $groups)));
    }
}
