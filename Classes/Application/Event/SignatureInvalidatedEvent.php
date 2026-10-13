<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Application\Event;

final readonly class SignatureInvalidatedEvent
{
    /**
     * @param list<string> $changedFields
     */
    public function __construct(
        public string $table,
        public int $uid,
        public int $language,
        public array $changedFields,
    ) {}
}
