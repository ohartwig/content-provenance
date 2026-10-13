<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Application\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;

/**
 * Rebuilds the derivatives of an original that is about to be replaced.
 *
 * Replacing a file makes TYPO3 delete every derivative of it
 * (`FileDeletionAspect::cleanupProcessedFilesPostFileReplace`). They come back
 * one by one, on the first page view that needs each of them. After a batch
 * run over dozens of originals that first view processes every image of the
 * page at once, and the run itself cannot say whether the new derivatives sign.
 *
 * So the processing configurations are read before the replace, and after it
 * each derivative is processed again. Processing dispatches
 * `AfterFileProcessingEvent`, and the derivative is signed there, like one a
 * visitor caused ({@see \Koh\ContentProvenance\Application\EventListener\ReSignDerivativeFiles}).
 */
final class DerivativeRegenerator implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly ProcessedFileRepository $processedFiles,
    ) {}

    /**
     * The derivatives that exist now, as task and configuration. Entries that
     * only point at the original (no file of their own) are left out: there is
     * nothing to rebuild.
     *
     * @return list<array{taskType: string, configuration: array<string, mixed>}>
     */
    public function snapshot(File $file): array
    {
        return $this->tasksOf($this->derivativesOf($file));
    }

    /**
     * @return list<ProcessedFile>
     */
    private function derivativesOf(File $file): array
    {
        $derivatives = [];
        foreach ($this->processedFiles->findAllByOriginalFile($file) as $processed) {
            if (!$processed->usesOriginalFile()) {
                $derivatives[] = $processed;
            }
        }

        return $derivatives;
    }

    /**
     * @param list<ProcessedFile> $derivatives
     *
     * @return list<array{taskType: string, configuration: array<string, mixed>}>
     */
    private function tasksOf(array $derivatives): array
    {
        $tasks = [];
        foreach ($derivatives as $processed) {
            $task = [
                'taskType' => $processed->getTaskIdentifier(),
                'configuration' => $processed->getProcessingConfiguration(),
            ];
            $tasks[sha1(serialize($task))] = $task;
        }

        return array_values($tasks);
    }

    /**
     * Deletes the derivatives of an original that stays as it is and builds
     * them again — for derivatives signed before they knew their parent.
     *
     * @return array{regenerated: int, failed: int}
     */
    public function rebuild(File $file): array
    {
        $derivatives = $this->derivativesOf($file);
        $snapshot = $this->tasksOf($derivatives);
        foreach ($derivatives as $processed) {
            try {
                $processed->delete(true);
            } catch (\Throwable $e) {
                // Left in place, it is found again by process() below and
                // stays as it was: unchanged, not broken.
                $this->logger?->warning('Derivative could not be deleted for the rebuild', [
                    'file_uid' => $file->getUid(),
                    'identifier' => $processed->getIdentifier(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->regenerate($file, $snapshot);
    }

    /**
     * @param list<array{taskType: string, configuration: array<string, mixed>}> $snapshot
     *
     * @return array{regenerated: int, failed: int}
     */
    public function regenerate(File $file, array $snapshot): array
    {
        $regenerated = 0;
        $failed = 0;
        foreach ($snapshot as $task) {
            try {
                $file->process($task['taskType'], $task['configuration']);
                ++$regenerated;
            } catch (\Throwable $e) {
                // The derivative is then built on the next page view instead,
                // as it would have been without this class.
                ++$failed;
                $this->logger?->warning('Derivative could not be regenerated', [
                    'file_uid' => $file->getUid(),
                    'task_type' => $task['taskType'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['regenerated' => $regenerated, 'failed' => $failed];
    }
}
