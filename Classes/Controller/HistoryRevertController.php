<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Controller;

use Ppl\PplRightsManagement\Configuration\AbstractRightsManagementModuleConfiguration;
use Ppl\PplRightsManagement\Domain\Repository\OverviewManagementRepository;
use Ppl\PplRightsManagement\Service\HistoryRevertService;
use Ppl\PplRightsManagement\Service\HistoryService;
use Ppl\PplRightsManagement\Service\RightsManagementAccessService;
use Ppl\PplRightsManagement\Service\RightsManagementSaveService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsController]
final class HistoryRevertController
{
    private const FORM_NAME = 'ppl_rights_management';
    private const FORM_ACTION = 'history-undo';

    public function __construct(
        private ?AbstractRightsManagementModuleConfiguration $moduleConfiguration = null,
        private ?UriBuilder $uriBuilder = null,
        private ?FormProtectionFactory $formProtectionFactory = null,
        private ?HistoryRevertService $revertService = null
    ) {}

    public function undoAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        try {
            $backendUser = ($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication ? $GLOBALS['BE_USER'] : null;
            if (!$backendUser instanceof BackendUserAuthentication || !$backendUser->isAdmin()) {
                throw new \RuntimeException('Undo requires administrator privileges.');
            }
            if (!$this->hasValidToken($request, $body)) {
                throw new \RuntimeException('The form token is invalid. Please reload the module and try again.');
            }

            $historyUid = (int)($body['history_uid'] ?? 0);
            if ($historyUid <= 0) {
                throw new \RuntimeException('No history entry was selected.');
            }

            $result = $this->getRevertService()->undo($historyUid);
            $severity = match ($result['status']) {
                'ok' => ContextualFeedbackSeverity::OK,
                'conflict' => ContextualFeedbackSeverity::WARNING,
                default => ContextualFeedbackSeverity::ERROR,
            };
            $this->addFlashMessage($result['message'], $this->statusTitle($result['status']), $severity);
        } catch (\Throwable $throwable) {
            $this->addFlashMessage($throwable->getMessage(), 'Undo failed', ContextualFeedbackSeverity::ERROR);
        }

        return new RedirectResponse($this->resolveReturnUrl($request, (string)($body['returnUrl'] ?? '')));
    }

    private function hasValidToken(ServerRequestInterface $request, array $body): bool
    {
        $formToken = trim((string)($body['form_token'] ?? ''));
        if ($formToken === '') {
            return false;
        }

        return $this->getFormProtectionFactory()
            ->createFromRequest($request)
            ->validateToken($formToken, self::FORM_NAME, self::FORM_ACTION);
    }

    private function statusTitle(string $status): string
    {
        return match ($status) {
            'ok' => 'Change undone',
            'conflict' => 'Undo not possible',
            default => 'Undo failed',
        };
    }

    private function resolveReturnUrl(ServerRequestInterface $request, string $returnUrl): string
    {
        $returnUrl = trim($returnUrl);
        if ($returnUrl !== '') {
            $sanitized = GeneralUtility::sanitizeLocalUrl($returnUrl);
            if ($sanitized !== '') {
                return $sanitized;
            }
        }

        $moduleConfiguration = $this->getModuleConfiguration();
        $module = $moduleConfiguration->getModule();
        $routeName = (string)$module['identifier'] . '.history';

        return (string)$this->getUriBuilder()->buildUriFromRoute($routeName);
    }

    private function addFlashMessage(string $message, string $title, ContextualFeedbackSeverity $severity): void
    {
        $queue = GeneralUtility::makeInstance(FlashMessageService::class)->getMessageQueueByIdentifier();
        $queue->enqueue(new FlashMessage($message, $title, $severity, true));
    }

    private function getRevertService(): HistoryRevertService
    {
        if (!$this->revertService instanceof HistoryRevertService) {
            $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
            $saveService = GeneralUtility::makeInstance(
                RightsManagementSaveService::class,
                $connectionPool,
                GeneralUtility::makeInstance(
                    OverviewManagementRepository::class,
                    $connectionPool,
                    GeneralUtility::makeInstance(ModuleProvider::class)
                ),
                GeneralUtility::makeInstance(RightsManagementAccessService::class)
            );
            $this->revertService = GeneralUtility::makeInstance(
                HistoryRevertService::class,
                GeneralUtility::makeInstance(HistoryService::class, $connectionPool),
                $saveService,
                $connectionPool
            );
        }

        return $this->revertService;
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

    private function getFormProtectionFactory(): FormProtectionFactory
    {
        if (!$this->formProtectionFactory instanceof FormProtectionFactory) {
            $this->formProtectionFactory = GeneralUtility::makeInstance(FormProtectionFactory::class);
        }

        return $this->formProtectionFactory;
    }
}
