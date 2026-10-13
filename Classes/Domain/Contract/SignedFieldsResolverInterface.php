<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Domain\Contract;

interface SignedFieldsResolverInterface
{
    /**
     * @return list<string>
     */
    public function getFieldsForTable(string $table): array;
}
