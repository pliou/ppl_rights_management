<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Configuration;

class RightsManagementModuleConfiguration extends AbstractRightsManagementModuleConfiguration
{
    protected function buildModule(): array
    {
        return [
            'identifier' => 'ppl_rights_management',
            'path' => '/module/system/ppl-rights-management',
            'title' => 'module.title',
        ];
    }

    protected function buildTabs(): array
    {
        return [
            'overview' => [
                'label' => 'tabs.overview.label',
                'description' => 'tabs.overview.description',
            ],
            'group-management' => [
                'label' => 'tabs.groupManagement.label',
                'description' => 'tabs.groupManagement.description',
            ],
            'group-rights-management' => [
                'label' => 'tabs.groupRightsManagement.label',
                'description' => 'tabs.groupRightsManagement.description',
            ],
            'module-management' => [
                'label' => 'tabs.moduleManagement.label',
                'description' => 'tabs.moduleManagement.description',
            ],
            'group-rights-inheritance-management' => [
                'label' => 'tabs.groupRightsInheritanceManagement.label',
                'description' => 'tabs.groupRightsInheritanceManagement.description',
            ],
            'backend-user-management' => [
                'label' => 'tabs.backendUserManagement.label',
                'description' => 'tabs.backendUserManagement.description',
            ],
            'mount-management' => [
                'label' => 'tabs.mountManagement.label',
                'description' => 'tabs.mountManagement.description',
            ],
            'history' => [
                'label' => 'tabs.history.label',
                'description' => 'tabs.history.description',
            ],
        ];
    }
}
