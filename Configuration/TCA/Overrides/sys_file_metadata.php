<?php

declare(strict_types=1);

defined('TYPO3') or exit;

/**
 * AI-Provenance fields on the FAL-Metadata table (`sys_file_metadata`).
 *
 * sys_file_metadata is the canonical per-file metadata store in TYPO3 FAL
 * and applies to ALL file types (images, audio, video, PDF). Tagging an
 * asset's origin here makes it inherit into every sys_file_reference that
 * binds the file (via TCA-default = ___RECORD lookups in sys_file_reference).
 *
 * Field semantics:
 *   - tx_provenance_origin: human/ai_generated/ai_assisted (enum AssetOrigin)
 *   - tx_provenance_ai_system: free-text model name (e.g. "DALL-E 3",
 *     "Suno v3", "RunwayML Gen-3") — OPTIONAL, only filled if known.
 *   - tx_provenance_ai_prompt: free-text prompt note for audit /
 *     reproducibility — OPTIONAL.
 *   - tx_provenance_detected_at: unix-timestamp, set by AutoDetectAiContentOnFileAdded
 *     when C2PA/IPTC tags are read from the file at upload.
 *   - tx_provenance_c2pa_status: readonly status (none / signed_own /
 *     signed_external / stripped) — visible to editor so they can verify
 *     external signing chain (e.g. „OpenAI-signed").
 */
(static function (): void {
    $columns = [
        'tx_provenance_origin' => [
            'exclude' => true,
            'label' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:sys_file_metadata.tx_provenance_origin',
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
            'label' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:sys_file_metadata.tx_provenance_ai_system',
            'description' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:sys_file_metadata.tx_provenance_ai_system.description',
            'displayCond' => 'FIELD:tx_provenance_origin:!=:human',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
                'max' => 100,
            ],
        ],
        'tx_provenance_ai_prompt' => [
            'exclude' => true,
            'label' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:sys_file_metadata.tx_provenance_ai_prompt',
            'description' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:sys_file_metadata.tx_provenance_ai_prompt.description',
            'displayCond' => 'FIELD:tx_provenance_origin:!=:human',
            'config' => [
                'type' => 'text',
                'rows' => 3,
                'cols' => 60,
            ],
        ],
        'tx_provenance_detected_at' => [
            'exclude' => true,
            'label' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:sys_file_metadata.tx_provenance_detected_at',
            'config' => [
                'type' => 'datetime',
                'format' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'tx_provenance_c2pa_status' => [
            'exclude' => true,
            'label' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:sys_file_metadata.tx_provenance_c2pa_status',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'readOnly' => true,
                'default' => 'none',
                'items' => [
                    ['label' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:provenance.c2pa.none', 'value' => 'none'],
                    ['label' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:provenance.c2pa.signed_own', 'value' => 'signed_own'],
                    ['label' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:provenance.c2pa.signed_external', 'value' => 'signed_external'],
                    ['label' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:provenance.c2pa.stripped', 'value' => 'stripped'],
                ],
            ],
        ],
    ];

    TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('sys_file_metadata', $columns);

    // Show the provenance fields as a dedicated palette + tab in the FAL
    // metadata edit form. Editors find them next to title/description.
    $GLOBALS['TCA']['sys_file_metadata']['palettes']['provenance'] = [
        'label' => 'LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:palette.provenance',
        'showitem' => 'tx_provenance_origin,'
            . ' tx_provenance_c2pa_status,'
            . '--linebreak--,'
            . ' tx_provenance_ai_system,'
            . ' tx_provenance_detected_at,'
            . '--linebreak--,'
            . ' tx_provenance_ai_prompt',
    ];

    TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
        'sys_file_metadata',
        '--div--;LLL:EXT:content_provenance/Resources/Private/Language/locallang_db.xlf:tab.provenance, --palette--;;provenance',
    );
})();
