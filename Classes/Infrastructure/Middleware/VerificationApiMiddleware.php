<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Infrastructure\Middleware;

use Koh\ContentProvenance\Application\Service\ContentVerifier;
use Koh\ContentProvenance\Domain\Contract\SignedFieldsResolverInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

final readonly class VerificationApiMiddleware implements MiddlewareInterface
{
    private const API_PATH = '/_provenance/api/verify';

    public function __construct(
        private ContentVerifier $contentVerifier,
        private SignedFieldsResolverInterface $signedFieldsResolver,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (self::API_PATH !== $path || 'GET' !== $request->getMethod()) {
            return $handler->handle($request);
        }

        $queryParams = $request->getQueryParams();
        $table = (string) ($queryParams['table'] ?? '');
        $uid = (int) ($queryParams['uid'] ?? 0);
        $language = (int) ($queryParams['language'] ?? 0);

        if ('' === $table || 0 === $uid) {
            return new JsonResponse(
                ['error' => 'Missing required parameters: table, uid'],
                400,
            );
        }

        $configuredFields = $this->signedFieldsResolver->getFieldsForTable($table);
        if ([] === $configuredFields) {
            return new JsonResponse(
                ['error' => 'Table not configured for provenance'],
                400,
            );
        }

        $result = $this->contentVerifier->verify(
            table: $table,
            uid: $uid,
            language: $language,
        );

        return new JsonResponse([
            'signed' => $result->signed,
            'signatureValid' => $result->signatureValid,
            'contentIntact' => $result->contentIntact,
            'revoked' => $result->revoked,
            'signedBy' => $result->signedBy,
            'signedAt' => $result->signedAt?->format(\DateTimeInterface::ATOM),
            'keyId' => $result->keyId,
            'contentHash' => $result->contentHash,
            'fullyVerified' => $result->isFullyVerified(),
        ]);
    }
}
