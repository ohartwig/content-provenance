<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Domain\Exception;

final class KeyNotFoundException extends \RuntimeException
{
    public static function forKeyId(string $keyId): self
    {
        return new self(
            message: sprintf('Key with ID "%s" was not found', $keyId),
        );
    }
}
