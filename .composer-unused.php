<?php

declare(strict_types=1);

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;

return static function (Configuration $config): Configuration {
    return $config
        ->addNamedFilter(NamedFilter::fromString('typo3/cms-backend'))
        ->addNamedFilter(NamedFilter::fromString('typo3/cms-fluid'))
    ;
};
