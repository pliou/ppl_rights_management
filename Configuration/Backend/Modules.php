<?php

declare(strict_types=1);

use Ppl\PplRightsManagement\Controller\BackendUserManagementController;
use Ppl\PplRightsManagement\Controller\GroupManagementController;
use Ppl\PplRightsManagement\Controller\GroupRightsInheritanceManagementController;
use Ppl\PplRightsManagement\Controller\GroupRightsManagementController;
use Ppl\PplRightsManagement\Controller\HistoryController;
use Ppl\PplRightsManagement\Controller\ModuleManagementController;
use Ppl\PplRightsManagement\Controller\MountManagementController;
use Ppl\PplRightsManagement\Controller\OverviewManagementController;
use Ppl\PplRightsManagement\Controller\RightsManagementSaveController;

return [
    'ppl_rights_management' => [
        'parent' => 'system',
        'position' => ['after' => '*'],
        'access' => 'user',
        'path' => '/module/system/ppl-rights-management',
        'icon' => 'EXT:ppl_rights_management/Resources/Public/Icons/module-ppl-rights-management.svg',
        'labels' => 'LLL:EXT:ppl_rights_management/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => OverviewManagementController::class . '::handleRequest',
            ],
            'overview' => [
                'path' => '/overview',
                'target' => OverviewManagementController::class . '::handleRequest',
            ],
            'group-management' => [
                'path' => '/group-management',
                'target' => GroupManagementController::class . '::handleRequest',
            ],
            'group-rights-management' => [
                'path' => '/group-rights-management',
                'target' => GroupRightsManagementController::class . '::handleRequest',
            ],
            'module-management' => [
                'path' => '/module-management',
                'target' => ModuleManagementController::class . '::handleRequest',
            ],
            'group-rights-inheritance-management' => [
                'path' => '/group-rights-inheritance-management',
                'target' => GroupRightsInheritanceManagementController::class . '::handleRequest',
            ],
            'backend-user-management' => [
                'path' => '/backend-user-management',
                'target' => BackendUserManagementController::class . '::handleRequest',
            ],
            'mount-management' => [
                'path' => '/mount-management',
                'target' => MountManagementController::class . '::handleRequest',
            ],
            'history' => [
                'path' => '/history',
                'methods' => ['GET', 'POST'],
                'target' => HistoryController::class . '::handleRequest',
            ],
            'save' => [
                'path' => '/save',
                'methods' => ['POST'],
                'target' => RightsManagementSaveController::class . '::saveAction',
            ],
        ],
    ],
];
