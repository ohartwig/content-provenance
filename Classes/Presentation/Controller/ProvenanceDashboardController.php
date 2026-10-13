<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Presentation\Controller;

use Koh\ContentProvenance\Domain\Contract\AuditLogRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

final class ProvenanceDashboardController extends ActionController
{
    public function __construct(
        private readonly AuditLogRepositoryInterface $auditLogRepository,
    ) {}

    public function indexAction(): ResponseInterface
    {
        $recentAuditEntries = $this->auditLogRepository->findRecent(limit: 20);

        $this->view->assignMultiple([
            'auditEntries' => $recentAuditEntries,
            'signedTables' => ['pages', 'tt_content'],
        ]);

        return $this->htmlResponse();
    }
}
