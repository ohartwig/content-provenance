<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Domain\Exception;

final class InvalidTableException extends \RuntimeException
{
    public static function forTable(string $table): self
    {
        return new self(
            message: sprintf('Table "%s" is not configured for content provenance signing', $table),
        );
    }
}
