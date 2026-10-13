<?php

declare(strict_types=1);

return [
    'frontend' => [
        'koh/content-provenance-verification-api' => [
            'target' => Koh\ContentProvenance\Infrastructure\Middleware\VerificationApiMiddleware::class,
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
            'after' => [
                'typo3/cms-frontend/site',
            ],
        ],
        'koh/content-provenance-asset-api' => [
            'target' => Koh\ContentProvenance\Infrastructure\Middleware\AssetProvenanceApiMiddleware::class,
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
            'after' => [
                'typo3/cms-frontend/site',
            ],
        ],
        'koh/content-provenance-well-known-keys' => [
            'target' => Koh\ContentProvenance\Infrastructure\Middleware\WellKnownKeysMiddleware::class,
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
            'after' => [
                'typo3/cms-frontend/site',
            ],
        ],
    ],
];
