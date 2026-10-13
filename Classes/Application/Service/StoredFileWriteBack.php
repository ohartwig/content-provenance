<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Application\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;

/**
 * Puts a modified writable copy of a FAL file back into its storage.
 *
 * `getForLocalProcessing(true)` never hands out the stored file. Every driver,
 * the local one included, returns a temporary copy (`var/transient/fal-tempfile-…`),
 * and whatever is written into that copy — IPTC tags, a C2PA manifest — is
 * lost unless it is written back. Until this class existed nothing did, so
 * every signature and every IPTC disclosure ended up in a temp file that was
 * never read again.
 *
 * The write-back goes through FAL, not through the filesystem, so that the
 * index (sha1, size) follows and remote drivers work as well. Replacing an
 * original makes FAL re-extract its metadata, which dispatches
 * `AfterFileMetaDataUpdatedEvent` — the very event that started the signing.
 * {@see isReplacing()} lets that listener recognise its own echo.
 *
 * Every method consumes the copy it is given: on success FAL has moved it
 * into place, on failure it is deleted. The copy must therefore come from
 * `getForLocalProcessing(true)` and never be the stored file itself.
 */
final class StoredFileWriteBack implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var array<int, true> sys_file uids whose replace is under way */
    private array $replacing = [];

    /**
     * Returns true when the stored file now holds the bytes of `$writableCopy`
     * — including the case where nothing changed and no write was needed.
     */
    public function replaceOriginal(File $file, string $writableCopy): bool
    {
        try {
            if (!is_file($writableCopy)) {
                return false;
            }
            if (sha1_file($writableCopy) === $file->getSha1()) {
                // Unchanged copy: replacing would only fire FAL events and
                // invalidate every derivative for nothing.
                return true;
            }

            $storage = $file->getStorage();
            // The replace is a consequence of a save the user was allowed to
            // make (or of a CLI run), not a file operation of their own. An
            // editor who may edit metadata but not replace files must still
            // get the disclosure into the file — the same reason core lifts
            // the check around its own internal writes.
            $evaluatePermissions = $storage->getEvaluatePermissions();
            $storage->setEvaluatePermissions(false);
            $this->replacing[$file->getUid()] = true;
            try {
                $storage->replaceFile($file, $writableCopy);
            } finally {
                unset($this->replacing[$file->getUid()]);
                $storage->setEvaluatePermissions($evaluatePermissions);
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger?->warning('Could not write the signed copy back to the storage', [
                'file_uid' => $file->getUid(),
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            $this->discard($writableCopy);
        }
    }

    /**
     * The derivative counterpart. A processed file is not indexed, so no
     * metadata event follows, but the file still has to go through the
     * processing folder's storage rather than be patched on disk.
     */
    public function replaceDerivative(ProcessedFile $processed, string $writableCopy): bool
    {
        try {
            if (!is_file($writableCopy)) {
                return false;
            }
            $processed->updateWithLocalFile($writableCopy);

            return true;
        } catch (\Throwable $e) {
            $this->logger?->warning('Could not write the signed derivative back to the storage', [
                'file_uid' => $processed->getOriginalFile()->getUid(),
                'identifier' => $processed->getIdentifier(),
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            $this->discard($writableCopy);
        }
    }

    /**
     * Drops a writable copy that is not going to be written back.
     */
    public function discard(string $writableCopy): void
    {
        if (is_file($writableCopy)) {
            @unlink($writableCopy);
        }
    }

    /**
     * True while `replaceOriginal()` is replacing this file — i.e. while FAL is
     * dispatching the events that the replace itself caused.
     */
    public function isReplacing(int $fileUid): bool
    {
        return isset($this->replacing[$fileUid]);
    }
}
