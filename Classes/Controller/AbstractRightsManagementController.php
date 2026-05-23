<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Controller;

use Ppl\PplRightsManagement\Configuration\AbstractRightsManagementModuleConfiguration;
use Ppl\PplRightsManagement\Service\RightsManagementAccessService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

abstract class AbstractRightsManagementController
{
    private const INSERT_PLUGIN_CONTENT_TYPE_WARNING = 'Editing of at least one plugin was enabled but editing the page content type "Insert Plugin" is still disallowed.';
    private const SHARED_TEMPLATE_PACKAGE = 'ppl/ppl_rights_management';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly AbstractRightsManagementModuleConfiguration $moduleConfiguration,
        private readonly UriBuilder $uriBuilder,
        private readonly PageRenderer $pageRenderer
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $definition = $this->moduleConfiguration->getDefinition();
        $extensionKey = $this->getTranslationExtensionKey($definition);
        $definition = $this->translateDefinition($definition, $extensionKey);
        $accessService = GeneralUtility::makeInstance(RightsManagementAccessService::class);
        $tabs = $this->filterAccessibleTabs($this->withGeneratedRoutes($definition['tabs']), $accessService);
        $routes = $this->filterAccessibleRoutes($this->withGeneratedRoutes($definition['routes']), $accessService);
        $activeTabId = $this->getActiveTabId($request);
        $activeTab = $tabs[$activeTabId] ?? $tabs[$this->moduleConfiguration->getDefaultTab()] ?? reset($tabs) ?: [
            'id' => $activeTabId,
            'label' => $definition['module']['title'],
            'description' => '',
            'route' => '',
        ];
        $canUseActiveTab = $accessService->canUseModule() && $accessService->canAccessTab($activeTabId);
        $moduleTemplate = $this->createModuleTemplate(
            $request,
            $canUseActiveTab ? $this->getTemplatePackageName() : self::SHARED_TEMPLATE_PACKAGE
        );
        $moduleTemplate->setModuleClass('rm-shell');
        $moduleTemplate->setTitle($definition['module']['title']);
        $this->removeStalePluginIntegrityFlashMessages();
        $this->loadUiAssets();
        $labels = $this->buildLabels($extensionKey);
        if (!$canUseActiveTab) {
            $moduleTemplate->assignMultiple([
                'activeTab' => $activeTab,
                'introText' => $this->translate($this->getIntroText(), $extensionKey),
                'labels' => $labels,
                'uiLabels' => $labels,
                'routeUrls' => $this->buildRouteUrls($routes),
                'routes' => $routes,
                'tabs' => $tabs,
                'title' => $definition['module']['title'],
            ]);

            return $moduleTemplate->renderResponse('RightsManagement/AccessDenied');
        }
        $moduleTemplate->assignMultiple([
            'activeTab' => $activeTab,
            'data' => $this->getViewData(),
            'introText' => $this->translate($this->getIntroText(), $extensionKey),
            'labels' => $labels,
            'uiLabels' => $labels,
            'routeUrls' => $this->buildRouteUrls($routes),
            'routes' => $routes,
            'tabs' => $tabs,
            'title' => $definition['module']['title'],
        ]);

        return $moduleTemplate->renderResponse($this->getTemplateName());
    }

    private function filterAccessibleTabs(array $tabs, RightsManagementAccessService $accessService): array
    {
        return array_filter(
            $tabs,
            fn(array $tab): bool => $accessService->canAccessTab((string)($tab['id'] ?? ''))
        );
    }

    private function filterAccessibleRoutes(array $routes, RightsManagementAccessService $accessService): array
    {
        return array_filter(
            $routes,
            fn(array $route): bool => (string)($route['tab'] ?? '') === ''
                || $accessService->canAccessTab((string)$route['tab'])
        );
    }

    protected function getActiveTabId(ServerRequestInterface $request): string
    {
        return $this->moduleConfiguration->getDefaultTab();
    }

    protected function getIntroText(): string
    {
        return 'module.intro';
    }

    protected function getTemplateName(): string
    {
        return 'RightsManagement/GroupManagement';
    }

    protected function getTemplatePackageName(): string
    {
        return self::SHARED_TEMPLATE_PACKAGE;
    }

    protected function getViewData(): array
    {
        return [];
    }

    private function withGeneratedRoutes(array $items): array
    {
        foreach ($items as $id => $item) {
            try {
                $items[$id]['route'] = (string)$this->uriBuilder->buildUriFromRoute($item['routeName']);
            } catch (RouteNotFoundException) {
                unset($items[$id]);
                continue;
            }
            $items[$id]['path'] = $items[$id]['route'];
        }

        return $items;
    }

    private function createModuleTemplate(ServerRequestInterface $request, string $packageName): ModuleTemplate
    {
        $route = $request->getAttribute('route');
        if ($route instanceof Route && $packageName !== '') {
            $route = clone $route;
            $route->setOption('packageName', $packageName);
            $request = $request->withAttribute('route', $route);
        }

        return $this->moduleTemplateFactory->create($request);
    }

    private function buildRouteUrls(array $routes): array
    {
        $routeUrls = [];
        foreach ($routes as $id => $route) {
            $routeUrls[$this->toCamelCase((string)$id)] = $route['route'];
        }

        return $routeUrls;
    }

    private function removeStalePluginIntegrityFlashMessages(): void
    {
        $queue = GeneralUtility::makeInstance(FlashMessageService::class)->getMessageQueueByIdentifier();
        $messages = $queue->getAllMessages();
        $hasStaleMessage = false;
        foreach ($messages as $message) {
            if ($this->isStalePluginIntegrityFlashMessage($message)) {
                $hasStaleMessage = true;
                break;
            }
        }
        if (!$hasStaleMessage) {
            return;
        }

        $queue->getAllMessagesAndFlush();
        foreach ($messages as $message) {
            if (!$this->isStalePluginIntegrityFlashMessage($message)) {
                $queue->enqueue($message);
            }
        }
    }

    private function isStalePluginIntegrityFlashMessage(object $message): bool
    {
        if (!method_exists($message, 'getTitle') || !method_exists($message, 'getMessage')) {
            return false;
        }

        return (string)$message->getTitle() === $this->translate('common.saveAborted', 'ppl_rights_management')
            && str_contains((string)$message->getMessage(), self::INSERT_PLUGIN_CONTENT_TYPE_WARNING);
    }

    private function toCamelCase(string $value): string
    {
        $parts = explode('-', $value);
        $first = array_shift($parts) ?? '';

        return $first . implode('', array_map('ucfirst', $parts));
    }

    private function loadUiAssets(): void
    {
        $assetBase = 'EXT:ppl_rights_management/Resources/Public/';
        $this->pageRenderer->addCssFile($assetBase . 'Css/rights-management.css');
        $this->pageRenderer->addJsFile($assetBase . 'JavaScript/rights-management-ui.js', 'module', true, false, '', true);
    }

    private function getTranslationExtensionKey(array $definition): string
    {
        return (string)($definition['module']['translationExtensionKey'] ?? $definition['module']['identifier']);
    }

    private function translateDefinition(array $definition, string $extensionKey): array
    {
        $definition['module']['title'] = $this->translate((string)$definition['module']['title'], $extensionKey);

        foreach ($definition['tabs'] as $tabId => $tab) {
            $definition['tabs'][$tabId]['label'] = $this->translate((string)$tab['label'], $extensionKey);
            $definition['tabs'][$tabId]['description'] = $this->translate((string)$tab['description'], $extensionKey);
        }

        foreach ($definition['routes'] as $routeId => $route) {
            $definition['routes'][$routeId]['label'] = $this->translate((string)$route['label'], $extensionKey);
        }

        return $definition;
    }

    private function translate(string $keyOrLabel, string $extensionKey): string
    {
        if ($keyOrLabel === '') {
            return '';
        }

        if (str_starts_with($keyOrLabel, 'LLL:')) {
            $translated = $GLOBALS['LANG']->sL($keyOrLabel);
            if ($translated !== '') {
                return $translated;
            }

            if ($extensionKey !== 'ppl_rights_management') {
                $fallbackKey = str_replace(
                    'EXT:' . $extensionKey . '/Resources/Private/Language/locallang.xlf:',
                    'EXT:ppl_rights_management/Resources/Private/Language/locallang.xlf:',
                    $keyOrLabel
                );
                if ($fallbackKey !== $keyOrLabel) {
                    $translated = $GLOBALS['LANG']->sL($fallbackKey);
                    if ($translated !== '') {
                        return $translated;
                    }
                }
            }

            return $keyOrLabel;
        }

        $translationKey = 'LLL:EXT:' . $extensionKey . '/Resources/Private/Language/locallang.xlf:' . $keyOrLabel;
        $translated = $GLOBALS['LANG']->sL($translationKey);
        if ($translated !== '') {
            return $translated;
        }

        if ($extensionKey !== 'ppl_rights_management') {
            $fallbackKey = 'LLL:EXT:ppl_rights_management/Resources/Private/Language/locallang.xlf:' . $keyOrLabel;
            $translated = $GLOBALS['LANG']->sL($fallbackKey);
            if ($translated !== '') {
                return $translated;
            }
        }

        return $keyOrLabel;
    }

    private function buildLabels(string $extensionKey): array
    {
        $keys = [
            'actions',
            'active',
            'addGroup',
            'applySelection',
            'area',
            'assignedRightsThisUser',
            'available',
            'backendGroups',
            'backendModule',
            'backendUsers',
            'cancel',
            'commonAssignedRights',
            'commonDbMounts',
            'commonDirectoryMounts',
            'commonMountsOfSelection',
            'combinationSearch',
            'current',
            'databaseMounts',
            'databaseMountSearch',
            'databasePermissions',
            'dbMountsAndDirectoryMounts',
            'delete',
            'description',
            'direct',
            'directAssignmentEdit',
            'directAtUser',
            'directGroup',
            'directoryMountSearch',
            'directoryMounts',
            'disabled',
            'discard',
            'draft',
            'edit',
            'entries',
            'file',
            'fileMountSearch',
            'fileMounts',
            'formPreparedNoDbWriteLater',
            'group',
            'groupSearch',
            'groups',
            'groupsSelected',
            'groupRightsInheritanceEdit',
            'hidden',
            'inherited',
            'inheritedState',
            'inheritedRightsReadonly',
            'maxEight',
            'maxTen',
            'modules',
            'moduleSearch',
            'modulePermissions',
            'mounts',
            'mountRightsPerBackendGroup',
            'noChanges',
            'noCommonRights',
            'noFileMountsFound',
            'noGroup',
            'noInheritedGroups',
            'noMoreGroups',
            'noPagesFound',
            'noPath',
            'noSelection',
            'noTitle',
            'noUsersSelected',
            'noRightsFound',
            'onlyCommonAssignedRights',
            'own',
            'overview',
            'overviewSearch',
            'pageTree',
            'pageTreeSearch',
            'pageType',
            'pageTypes',
            'partial',
            'permissionSearch',
            'read',
            'readShort',
            'readAndWrite',
            'readOnly',
            'recordPermissions',
            'remove',
            'rights',
            'rightSearch',
            'save',
            'saveChanges',
            'selected',
            'selectedGroup',
            'showActive',
            'showInactive',
            'source',
            'status',
            'table',
            'tablePermissions',
            'tablesRead',
            'tablesWrite',
            'title',
            'userManagementHint',
            'userSearch',
            'users',
            'usersSelected',
            'viaGroups',
            'warningDraftNotPersisted',
            'write',
            'writeDisabledByConfig',
            'inheritsFromUid',
            'writeNotActive',
            'groupTitleExample',
            'groupDescriptionPlaceholder',
            'add',
            'addInheritance',
            'all',
            'admin',
            'assignments',
            'noPermission',
            'noneShort',
            'none',
            'scope',
            'access',
            'writeShort',
            'inheritance',
        ];
        $labels = [];
        foreach ($keys as $key) {
            $labels[$key] = $this->translate('common.' . $key, $extensionKey);
        }
        $labels['accessDeniedTitle'] = $this->translate('accessDenied.title', $extensionKey);
        $labels['accessDeniedDescription'] = $this->translate('accessDenied.description', $extensionKey);
        $labels['accessDeniedHint'] = $this->translate('accessDenied.hint', $extensionKey);

        return $labels;
    }
}
