<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Infrastructure\Middleware;

use Koh\ContentProvenance\Domain\Contract\KeyProviderInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

final readonly class WellKnownKeysMiddleware implements MiddlewareInterface
{
    private const WELL_KNOWN_PATH = '/.well-known/provenance-keys';

    public function __construct(
        private KeyProviderInterface $keyProvider,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (self::WELL_KNOWN_PATH !== $path || 'GET' !== $request->getMethod()) {
            return $handler->handle($request);
        }

        $keyId = $this->keyProvider->getCurrentKeyId();
        $publicKey = $this->keyProvider->getPublicKeyById($keyId);

        return new JsonResponse([
            'keys' => [
                [
                    'keyId' => $keyId,
                    'publicKey' => $publicKey,
                    'algorithm' => 'Ed25519',
                ],
            ],
        ]);
    }
}
