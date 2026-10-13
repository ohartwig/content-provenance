<?php

declare(strict_types=1);

defined('TYPO3') or exit;

// Backend only. Frontend assets deliberately live under Resources/Public/Frontend/
// so they do NOT end up here: this registration pulls in every stylesheet in the
// named folder, so a frontend file placed beside dashboard.css would be injected
// into every backend request.
$GLOBALS['TYPO3_CONF_VARS']['BE']['stylesheets']['content_provenance']
    = 'EXT:content_provenance/Resources/Public/Css/';

// Cache for the media detection endpoint. The endpoint is public and
// unauthenticated by design, and answering it means spawning c2patool and
// exiftool — so caching is what keeps it from being a way to fork processes on
// our server. The key carries the file's size, mtime and sha1, so a re-upload
// misses rather than serving a stale answer.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['content_provenance_asset'] ??= [
    'frontend' => TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend' => TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
    'options' => [
        'defaultLifetime' => 86400,
    ],
];
