<?php

declare(strict_types=1);

defined('TYPO3') or exit;

/**
 * Optional per-reference Provenance-Override.
 *
 * The canonical asset-origin lives on `sys_file_metadata` (one row per FAL
 * file). For 99% of cases the metadata-record's tx_provenance_* fields
 * apply unchanged to every reference. This override only exists for the
 * edge-case where editors want to mark a specific occurrence differently
 * (e.g. a Human-tagged file used in a context where AI-disclosure is
 * desired, or vice-versa).
 *
 * Empty values on the reference fall back to the file-metadata values via
 * sys_file_metadata's defaults — TYPO3 FAL pattern.
 */
(static function (): void {
    $columns = [
        'tx_provenance_origin' => [
            'exclude' => true,
            'label' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:sys_file_reference.tx_provenance_origin',
            'description' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:sys_file_reference.tx_provenance_origin.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'default' => '',
                'items' => [
                    [
                        'label' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:provenance.origin.inherit',
                        'value' => '',
                    ],
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
    ];

    TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('sys_file_reference', $columns);
})();
