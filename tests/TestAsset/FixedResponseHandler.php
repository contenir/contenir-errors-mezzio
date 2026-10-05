<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\Tests\TestAsset;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A request handler that returns the same response every time, standing in for
 * the inner pipeline the middleware wraps.
 */
final readonly class FixedResponseHandler implements RequestHandlerInterface
{
    public function __construct(
        private ResponseInterface $response,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response;
    }
}
