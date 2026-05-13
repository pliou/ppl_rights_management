<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Configuration;

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

abstract class AbstractRightsManagementModuleConfiguration
{
    final public function getDefinition(): array
    {
        $module = $this->buildModule();
        $tabs = $this->normalizeTabs($this->filterAvailableTabs($this->buildTabs()), $module['identifier']);

        return [
            'module' => $module,
            'tabs' => $tabs,
            'routes' => $this->buildRoutes($tabs, $module['identifier']),
        ];
    }

    final public function getModule(): array
    {
        return $this->getDefinition()['module'];
    }

    final public function getTabs(): array
    {
        return $this->getDefinition()['tabs'];
    }

    final public function getRoutes(): array
    {
        return $this->getDefinition()['routes'];
    }

    final public function getDefaultTab(): string
    {
        $tabs = $this->getTabs();

        if ($tabs === []) {
            return '';
        }

        return $tabs[array_key_first($tabs)]['id'];
    }

    final public function getTab(string $tabId): array
    {
        $tabs = $this->getTabs();

        return $tabs[$tabId] ?? $tabs[$this->getDefaultTab()];
    }

    abstract protected function buildModule(): array;

    protected function buildTabs(): array
    {
        return [];
    }

    protected function overrideModule(array $baseModule, array $overrides): array
    {
        return array_replace($baseModule, $overrides);
    }

    protected function overrideTabs(array $baseTabs, array $overrides): array
    {
        foreach ($overrides as $tabId => $tabConfiguration) {
            if ($tabConfiguration === null) {
                unset($baseTabs[$tabId]);
                continue;
            }

            $baseTabs[$tabId] = array_replace($baseTabs[$tabId] ?? [], $tabConfiguration);
        }

        return $baseTabs;
    }

    protected function normalizeTabs(array $tabs, string $moduleIdentifier): array
    {
        $normalizedTabs = [];

        foreach ($tabs as $id => $tabConfiguration) {
            $tabId = $tabConfiguration['id'] ?? (string)$id;
            $normalizedTabs[$tabId] = [
                'id' => $tabId,
                'label' => $tabConfiguration['label'] ?? $tabId,
                'description' => $tabConfiguration['description'] ?? '',
                'routeName' => $this->buildTabRouteName($moduleIdentifier, $tabId),
                'requiredExtensions' => (array)($tabConfiguration['requiredExtensions'] ?? []),
            ];
        }

        return $normalizedTabs;
    }

    protected function buildRoutes(array $tabs, string $moduleIdentifier): array
    {
        $routes = [];

        foreach ($tabs as $tabId => $tabConfiguration) {
            $routes[$tabId] = [
                'routeName' => $tabConfiguration['routeName'],
                'tab' => $tabId,
                'label' => $tabConfiguration['label'],
            ];
        }
        $routes['save'] = [
            'routeName' => $this->buildTabRouteName($moduleIdentifier, 'save'),
            'tab' => '',
            'label' => 'common.save',
        ];

        return $routes;
    }

    protected function buildTabRouteName(string $moduleIdentifier, string $tabId): string
    {
        return $moduleIdentifier . '.' . $tabId;
    }

    private function filterAvailableTabs(array $tabs): array
    {
        return array_filter($tabs, fn(array $tabConfiguration): bool => $this->hasRequiredExtensions($tabConfiguration));
    }

    private function hasRequiredExtensions(array $tabConfiguration): bool
    {
        foreach ((array)($tabConfiguration['requiredExtensions'] ?? []) as $extensionKey) {
            if (!ExtensionManagementUtility::isLoaded((string)$extensionKey)) {
                return false;
            }
        }

        return true;
    }
}
