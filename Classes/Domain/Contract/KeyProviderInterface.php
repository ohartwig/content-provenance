<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Domain\Contract;

use Koh\ContentProvenance\Domain\Model\KeyPair;

interface KeyProviderInterface
{
    public function getSigningKeyPair(): KeyPair;

    public function getPublicKeyById(string $keyId): string;

    public function getCurrentKeyId(): string;

    public function rotateKey(): KeyPair;
}
