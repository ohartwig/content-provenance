<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Domain\Contract;

interface RecordLoaderInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function load(string $table, int $uid, int $language): ?array;
}
