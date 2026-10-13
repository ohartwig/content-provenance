<?php

declare(strict_types=1);

defined('TYPO3') or exit;

/**
 * Page-level Provenance — for cases where the BODY content (RTE / generated
 * articles) is AI-generated, independent of any media references on the page.
 *
 * Example: an editor uses ChatGPT to draft a blog post body, then refines
 * it. Page-level origin = AiAssisted. The hero image may simultaneously
 * carry its own (different) origin via sys_file_metadata.
 */
(static function (string $table): void {
    $columns = [
        'tx_provenance_origin' => [
            'exclude' => true,
            'label' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:pages.tx_provenance_origin',
            'description' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:pages.tx_provenance_origin.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'default' => 'human',
                'items' => [
                    [
                        'label' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:provenance.origin.human',
                        'value' => 'human',
                    ],
                    [
                        'label' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:provenance.origin.ai_generated',
                        'value' => 'ai_generated',
                    ],
                    [
                        'label' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:provenance.origin.ai_assisted',
                        'value' => 'ai_assisted',
                    ],
                ],
            ],
        ],
        'tx_provenance_ai_system' => [
            'exclude' => true,
            'label' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:pages.tx_provenance_ai_system',
            'displayCond' => 'FIELD:tx_provenance_origin:!=:human',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
                'max' => 100,
            ],
        ],
    ];

    TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns($table, $columns);

    $GLOBALS['TCA'][$table]['palettes']['provenance'] = [
        'label' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:palette.provenance',
        'showitem' => 'tx_provenance_origin, tx_provenance_ai_system',
    ];

    TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
        $table,
        '--div--;LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:tab.provenance, --palette--;;provenance',
        '1', // doktype 1 = standard page only — folders/links/menu-separators excluded
    );
})('pages');
