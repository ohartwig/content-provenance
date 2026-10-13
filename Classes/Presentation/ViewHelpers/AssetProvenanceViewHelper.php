<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Presentation\ViewHelpers;

use Koh\ContentProvenance\Infrastructure\Persistence\AssetProvenanceFetcher;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Returns the AI-provenance data for a FAL file UID as a Fluid-array.
 *
 * Pure data-fetcher — does NOT render markup. Template decides what to do
 * with the result (typically: `<f:render partial="AiContentBadge" .../>` if
 * origin is ai_generated / ai_assisted).
 *
 * Usage:
 *   <html xmlns:bp="http://typo3.org/ns/Moselwal/SitepackageBase/ViewHelpers">
 *   <f:variable name="prov" value="{bp:assetProvenance(fileUid: file.originalFile.uid)}"/>
 *   <f:render partial="AiContentBadge" arguments="{origin: prov.origin, aiSystem: prov.aiSystem}"/>
 *
 * Result keys: origin (string), aiSystem (string), c2paStatus (string).
 */
final class AssetProvenanceViewHelper extends AbstractViewHelper
{
    public function __construct(
        private readonly AssetProvenanceFetcher $fetcher,
    ) {}

    public function initializeArguments(): void
    {
        $this->registerArgument('fileUid', 'int', 'sys_file.uid of the asset', true);
    }

    /**
     * @return array{origin: string, aiSystem: string, c2paStatus: string}
     */
    public function render(): array
    {
        $fileUid = (int) $this->arguments['fileUid'];

        return $this->fetcher->fetchForFile($fileUid);
    }
}
