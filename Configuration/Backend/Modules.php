<?php

declare(strict_types=1);

use Koh\ContentProvenance\Presentation\Controller\ProvenanceDashboardController;

return [
    'content_provenance' => [
        'parent' => 'web',
        'position' => ['after' => 'web_info'],
        'access' => 'user',
        'iconIdentifier' => 'content-provenance-module',
        'labels' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang.xlf',
        'extensionName' => 'ContentProvenance',
        'controllerActions' => [
            ProvenanceDashboardController::class => ['index'],
        ],
    ],
];
