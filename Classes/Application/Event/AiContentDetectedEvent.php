<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Application\Event;

final readonly class AiContentDetectedEvent
{
    public function __construct(
        public string $table,
        public int $uid,
        public string $aiProvider,
        public string $aiModel,
    ) {}
}
