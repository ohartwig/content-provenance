<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Application\Event;

final readonly class ContentSignedEvent
{
    public function __construct(
        public string $table,
        public int $uid,
        public int $language,
        public string $contentHash,
        public string $signedByName,
        public \DateTimeImmutable $signedAt,
    ) {}
}
