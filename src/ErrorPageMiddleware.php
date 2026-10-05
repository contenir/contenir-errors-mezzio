<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio;

use Contenir\Errors\ErrorPage;
use Contenir\Errors\ErrorPageRepositoryInterface;
use Laminas\Diactoros\StreamFactory;
use Mezzio\Template\TemplateRendererInterface;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

use function sprintf;
use function str_contains;
use function strtolower;

/**
 * Re-renders any 4xx/5xx HTML response with admin-authored content for its
 * status, when the repository holds a page for it.
 *
 * Piped outermost, ahead of Mezzio's ErrorHandler, so the status is settled by
 * the time the inner pipeline returns: the NotFoundHandler has produced its 404,
 * the ErrorHandler has turned an uncaught exception into a 500, or a handler
 * has returned a 403 of its own. One check after the handler covers every path.
 *
 * When no page is configured for the status the response passes through
 * unchanged, so the middleware is non-invasive on first install.
 *
 * Logging is independent of the admin content: every 4xx/5xx is reported to the
 * optional PSR-3 logger, so a site can watch its error volume whether or not a
 * page has been authored.
 *
 * @api
 */
final readonly class ErrorPageMiddleware implements MiddlewareInterface
{
    /**
     * Content types the middleware treats as an HTML page. A response without a
     * Content-Type header counts as HTML too: Mezzio's ErrorResponseGenerator
     * writes its templated 500 into a bare response and sets none.
     */
    private const array HTML_CONTENT_TYPES = ['text/html', 'application/xhtml+xml'];

    public function __construct(
        private ErrorPageRepositoryInterface $repository,
        private TemplateRendererInterface $renderer,
        private ?LoggerInterface $logger = null,
        private ErrorPageOptions $options = new ErrorPageOptions(),
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $status   = $response->getStatusCode();

        if ($status < 400) {
            return $response;
        }

        $this->log($request, $status);

        /**
         * In debug mode the middleware still logs, but otherwise stays out of the
         * way so the ErrorHandler's development output (Whoops, or the exception
         * and stack trace) reaches the browser intact. Without this the polite
         * admin-authored page would always win: right in production, the wrong
         * default while debugging a 500.
         */
        if ($this->options->debug || ! $this->isHtml($response)) {
            return $response;
        }

        $page = $this->repository->get($status);
        if (null === $page || $page->isEmpty()) {
            return $response;
        }

        return $this->render($response, $page);
    }

    private function render(ResponseInterface $response, ErrorPage $page): ResponseInterface
    {
        $params = [
            'status' => $page->status,
            'title'  => $page->title,
            'body'   => $page->body,
        ];

        if (null !== $this->options->layout) {
            $params['layout'] = $this->options->layout;
        }

        $html = $this->renderer->render($this->options->viewTemplate, $params);

        return $response->withBody((new StreamFactory())->createStream($html))
            ->withoutHeader('Content-Length')
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function isHtml(ResponseInterface $response): bool
    {
        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        if ('' === $contentType) {
            return true;
        }

        foreach (self::HTML_CONTENT_TYPES as $htmlType) {
            if (str_contains($contentType, $htmlType)) {
                return true;
            }
        }

        return false;
    }

    private function log(ServerRequestInterface $request, int $status): void
    {
        if (null === $this->logger) {
            return;
        }

        $message = sprintf('HTTP %d at %s', $status, (string) $request->getUri());

        if ($status >= 500) {
            $this->logger->error($message);
            return;
        }

        $this->logger->info($message);
    }
}
