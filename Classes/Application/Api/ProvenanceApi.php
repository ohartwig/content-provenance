<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Application\Api;

use Koh\ContentProvenance\Application\Event\AiContentDetectedEvent;
use Koh\ContentProvenance\Domain\Contract\AuditLoggerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class ProvenanceApi
{
    public function __construct(
        private AuditLoggerInterface $auditLogger,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    public function markAsAiGenerated(
        string $table,
        int $uid,
        int $language,
        string $aiProvider,
        string $aiModel,
        ?int $reviewedBy = null,
        ?string $reviewedByName = null,
    ): void {
        $this->auditLogger->logAiGeneration(
            table: $table,
            uid: $uid,
            language: $language,
            aiProvider: $aiProvider,
            aiModel: $aiModel,
            reviewedBy: $reviewedBy,
            reviewedByName: $reviewedByName,
        );

        $this->eventDispatcher->dispatch(new AiContentDetectedEvent(
            table: $table,
            uid: $uid,
            aiProvider: $aiProvider,
            aiModel: $aiModel,
        ));
    }

    public function markAsAiReviewed(
        string $table,
        int $uid,
        int $language,
        int $reviewedBy,
        string $reviewedByName,
    ): void {
        $this->auditLogger->logAiReview(
            table: $table,
            uid: $uid,
            language: $language,
            reviewedBy: $reviewedBy,
            reviewedByName: $reviewedByName,
        );
    }
}
