<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Application\Service;

final readonly class ContentHasher
{
    /**
     * Computes a deterministic SHA-256 hash of the specified record fields.
     *
     * @param array<string, mixed> $record
     * @param list<string>         $fieldsToSign
     */
    public function hashRecord(
        string $table,
        int $uid,
        int $language,
        array $record,
        array $fieldsToSign,
    ): string {
        $sortedFields = $fieldsToSign;
        sort($sortedFields);

        $dataToHash = [];
        foreach ($sortedFields as $field) {
            $dataToHash[$field] = $record[$field] ?? null;
        }

        $canonical = json_encode(
            [
                'table' => $table,
                'uid' => $uid,
                'language' => $language,
                'fields' => $dataToHash,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return hash('sha256', $canonical);
    }
}
