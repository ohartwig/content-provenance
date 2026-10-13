<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Infrastructure\KeyManagement;

use Koh\ContentProvenance\Domain\Contract\KeyProviderInterface;
use Koh\ContentProvenance\Domain\Enum\KeyProviderType;
use Koh\ContentProvenance\Infrastructure\KeyManagement\Provider\FileKeyProvider;
use Koh\ContentProvenance\Infrastructure\KeyManagement\Provider\Typo3KeyProvider;

final readonly class KeyProviderFactory
{
    public function __construct(
        private KeyProviderType $providerType,
        private Typo3KeyProvider $typo3KeyProvider,
        private FileKeyProvider $fileKeyProvider,
    ) {}

    public function create(): KeyProviderInterface
    {
        return match ($this->providerType) {
            KeyProviderType::TYPO3 => $this->typo3KeyProvider,
            KeyProviderType::FILE => $this->fileKeyProvider,
            KeyProviderType::VAULT => throw new \RuntimeException('Vault key provider is not available. Install the vault provider package.'),
        };
    }
}
