<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Configuration\Backend;

use Ppl\PplRightsManagement\Controller\BackendUserManagementController;
use Ppl\PplRightsManagement\Controller\GroupManagementController;
use Ppl\PplRightsManagement\Controller\GroupRightsInheritanceManagementController;
use Ppl\PplRightsManagement\Controller\GroupRightsManagementController;
use Ppl\PplRightsManagement\Controller\HistoryController;
use Ppl\PplRightsManagement\Controller\HistoryRevertController;
use Ppl\PplRightsManagement\Controller\ModuleManagementController;
use Ppl\PplRightsManagement\Controller\MountManagementController;
use Ppl\PplRightsManagement\Controller\OverviewManagementController;
use Ppl\PplRightsManagement\Controller\RightsManagementSaveController;

/**
 * Single source of truth for the `ppl_rights_management` backend module definition.
 *
 * TYPO3 v12 merges same-identifier module definitions across extensions with `array_merge`
 * (last active package wins, FULL replacement - see
 * {@see \TYPO3\CMS\Core\Package\AbstractServiceProvider::configureBackendModules()}).
 * A purely additive "routes only" contributor is therefore impossible: whichever extension
 * loads last must provide the COMPLETE definition. So instead of three drifting full copies,
 * the base extension and the HDA add-ons all build their complete definition from this one
 * source and only pass their own branding and route deltas.
 */
final class RightsManagementModuleDefinition
{
    /**
     * @param array<string, mixed> $branding extension-specific shell keys (icon/iconIdentifier, labels)
     * @param array<string, array<string, mixed>> $extraRoutes routes the add-on adds
     * @param array<string, array<string, mixed>> $routeOverrides per-route option overrides (e.g. a custom controller)
     * @return array<string, array<string, mixed>>
     */
    public static function build(array $branding, array $extraRoutes = [], array $routeOverrides = []): array
    {
        $routes = self::baseRoutes();
        foreach ($routeOverrides as $routeId => $override) {
            $routes[$routeId] = array_replace($routes[$routeId] ?? [], $override);
        }

        $module = array_replace(
            [
                'parent' => 'system',
                'position' => ['after' => '*'],
                'access' => 'user',
                'path' => '/module/system/ppl-rights-management',
            ],
            $branding,
            ['routes' => $routes + $extraRoutes]
        );

        return ['ppl_rights_management' => $module];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function baseRoutes(): array
    {
        return [
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
            'history-undo' => [
                'path' => '/history/undo',
                'methods' => ['POST'],
                'target' => HistoryRevertController::class . '::undoAction',
            ],
            'save' => [
                'path' => '/save',
                'methods' => ['POST'],
                'target' => RightsManagementSaveController::class . '::saveAction',
            ],
        ];
    }
}
