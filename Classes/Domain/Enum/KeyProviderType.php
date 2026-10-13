<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Domain\Enum;

enum KeyProviderType: string
{
    case VAULT = 'vault';
    case FILE = 'file';
    case TYPO3 = 'typo3';
}
