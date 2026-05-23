<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Controller;

use Ppl\PplRightsManagement\Configuration\AbstractRightsManagementModuleConfiguration;
use Ppl\PplRightsManagement\Domain\Repository\OverviewManagementRepository;
use Ppl\PplRightsManagement\Service\HistoryService;
use Ppl\PplRightsManagement\Service\RightsManagementAccessService;
use Ppl\PplRightsManagement\Service\RightsManagementSaveService;
use Psr\Log\LogLevel;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsController]
class RightsManagementSaveController
{
    private ?RightsManagementSaveService $saveService = null;
    private ?AbstractRightsManagementModuleConfiguration $moduleConfiguration = null;
    private ?UriBuilder $uriBuilder = null;

    public function __construct(
        ?RightsManagementSaveService $saveService = null,
        ?AbstractRightsManagementModuleConfiguration $moduleConfiguration = null,
        ?UriBuilder $uriBuilder = null
    ) {
        $this->saveService = $saveService;
        $this->moduleConfiguration = $moduleConfiguration;
        $this->uriBuilder = $uriBuilder;
    }

    public function saveAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $scope = (string)($body['scope'] ?? '');
        $message = '';

        try {
            $payload = $this->decodePayload((string)($body['payload'] ?? '{}'));
            $result = $this->getSaveService()->save($scope, $payload);
            $message = (string)$result['message'];
            GeneralUtility::makeInstance(HistoryService::class, GeneralUtility::makeInstance(ConnectionPool::class))
                ->recordChange($scope, $payload, $message, is_array($result['history'] ?? null) ? $result['history'] : []);
            $this->logSave('info', $message, $scope, $payload);
            $this->addFlashMessage($message, $this->translate('common.saved'), ContextualFeedbackSeverity::OK);
        } catch (\Throwable $throwable) {
            $message = $throwable->getMessage();
            $this->logSave('error', $message, $scope, $body);
            $this->addFlashMessage($message, $this->translate('common.saveAborted'), ContextualFeedbackSeverity::ERROR);
        }

        $returnUrl = $this->resolveReturnUrl($request, (string)($body['returnUrl'] ?? ''));

        return new RedirectResponse($this->withoutSaveFeedback($returnUrl));
    }

    private function decodePayload(string $payload): array
    {
        $payload = trim($payload);
        if ($payload === '') {
            return [];
        }
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('The save data could not be read.');
        }

        return $decoded;
    }

    private function addFlashMessage(string $message, string $title, ContextualFeedbackSeverity $severity): void
    {
        $queue = GeneralUtility::makeInstance(FlashMessageService::class)->getMessageQueueByIdentifier();
        $queue->enqueue(new FlashMessage($message, $title, $severity, true));
    }

    private function translate(string $key): string
    {
        $label = $GLOBALS['LANG']->sL('LLL:EXT:ppl_rights_management/Resources/Private/Language/locallang.xlf:' . $key);

        return $label !== '' ? $label : $key;
    }

    private function resolveReturnUrl(ServerRequestInterface $request, string $returnUrl): string
    {
        $returnUrl = trim($returnUrl);
        if ($returnUrl !== '') {
            if (str_starts_with($returnUrl, '/') && !str_starts_with($returnUrl, '//')) {
                return $returnUrl;
            }
            $currentOrigin = $request->getUri()->getScheme() . '://' . $request->getUri()->getAuthority();
            if ($currentOrigin !== '://' && str_starts_with($returnUrl, $currentOrigin)) {
                $pathAndQuery = substr($returnUrl, strlen($currentOrigin));
                if ($pathAndQuery !== '' && str_starts_with($pathAndQuery, '/')) {
                    return $pathAndQuery;
                }
            }
        }

        $moduleConfiguration = $this->getModuleConfiguration();
        $module = $moduleConfiguration->getModule();
        $routeName = (string)$module['identifier'] . '.' . $moduleConfiguration->getDefaultTab();

        return (string)$this->getUriBuilder()->buildUriFromRoute($routeName);
    }

    private function withoutSaveFeedback(string $returnUrl): string
    {
        $parts = parse_url($returnUrl);
        $query = [];
        if (is_array($parts) && isset($parts['query'])) {
            parse_str((string)$parts['query'], $query);
        }
        unset($query['rmSaveStatus'], $query['rmSaveMessage']);
        $path = is_array($parts) ? (string)($parts['path'] ?? '') : $returnUrl;
        $fragment = is_array($parts) && isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        $queryString = http_build_query($query);

        return $path . ($queryString !== '' ? '?' . $queryString : '') . $fragment;
    }

    private function getSaveService(): RightsManagementSaveService
    {
        if (!$this->saveService instanceof RightsManagementSaveService) {
            $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
            $repository = GeneralUtility::makeInstance(
                OverviewManagementRepository::class,
                $connectionPool,
                GeneralUtility::makeInstance(ModuleProvider::class)
            );
            $this->saveService = GeneralUtility::makeInstance(
                RightsManagementSaveService::class,
                $connectionPool,
                $repository,
                GeneralUtility::makeInstance(RightsManagementAccessService::class)
            );
        }

        return $this->saveService;
    }

    private function getModuleConfiguration(): AbstractRightsManagementModuleConfiguration
    {
        if (!$this->moduleConfiguration instanceof AbstractRightsManagementModuleConfiguration) {
            $this->moduleConfiguration = GeneralUtility::makeInstance(AbstractRightsManagementModuleConfiguration::class);
        }

        return $this->moduleConfiguration;
    }

    private function getUriBuilder(): UriBuilder
    {
        if (!$this->uriBuilder instanceof UriBuilder) {
            $this->uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        }

        return $this->uriBuilder;
    }

    private function logSave(string $level, string $message, string $scope, array $context): void
    {
        try {
            $logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
            $logContext = [
                'scope' => $scope,
                'context' => $context,
            ];
            if ($level === 'error') {
                $logger->log(LogLevel::ERROR, $message, $logContext);
                return;
            }
            $logger->log(LogLevel::INFO, $message, $logContext);
        } catch (\Throwable) {
        }
    }
}
