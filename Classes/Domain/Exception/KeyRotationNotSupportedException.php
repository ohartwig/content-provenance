<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Domain\Exception;

final class KeyRotationNotSupportedException extends \RuntimeException
{
    public static function forProvider(string $providerName): self
    {
        return new self(
            message: sprintf('Key rotation is not supported by the "%s" key provider', $providerName),
        );
    }
}
