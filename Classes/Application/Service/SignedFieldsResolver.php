<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Application\Service;

use Koh\ContentProvenance\Domain\Contract\SignedFieldsResolverInterface;

final readonly class SignedFieldsResolver implements SignedFieldsResolverInterface
{
    /**
     * @param array<string, list<string>> $tableFieldsConfig
     */
    public function __construct(
        private array $tableFieldsConfig,
    ) {}

    /**
     * @return list<string>
     */
    public function getFieldsForTable(string $table): array
    {
        return $this->tableFieldsConfig[$table] ?? [];
    }
}
