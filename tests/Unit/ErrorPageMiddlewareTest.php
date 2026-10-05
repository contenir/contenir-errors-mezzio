<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\Tests\Unit;

use Contenir\Errors\ErrorPage;
use Contenir\Errors\Mezzio\ErrorPageMiddleware;
use Contenir\Errors\Mezzio\ErrorPageOptions;
use Contenir\Errors\Mezzio\Tests\TestAsset\CountingViewStateReset;
use Contenir\Errors\Mezzio\Tests\TestAsset\FakeTemplateRenderer;
use Contenir\Errors\Mezzio\Tests\TestAsset\FixedResponseHandler;
use Contenir\Errors\Mezzio\Tests\TestAsset\InMemoryLogger;
use Contenir\Errors\Repository\InMemoryRepository;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LogLevel;

#[Group('unit')]
#[Group('middleware')]
final class ErrorPageMiddlewareTest extends TestCase
{
    private const string URI = 'https://www.example.test/missing';

    private FakeTemplateRenderer $renderer;

    private InMemoryRepository $repository;

    /**
     * @return array<string, array{int}>
     */
    public static function configuredErrorStatusProvider(): array
    {
        return [
            'forbidden'             => [403],
            'not found'             => [404],
            'internal server error' => [500],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function htmlContentTypeProvider(): array
    {
        return [
            'html'               => ['text/html'],
            'html with charset'  => ['text/html; charset=utf-8'],
            'html in upper case' => ['TEXT/HTML'],
            'xhtml'              => ['application/xhtml+xml'],
            'xhtml with charset' => ['application/xhtml+xml; charset=utf-8'],
        ];
    }

    /**
     * @return array<string, array{string|false}>
     */
    public static function layoutProvider(): array
    {
        return [
            'layout disabled' => [false],
            'named layout'    => ['layout::error'],
        ];
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function loggedStatusProvider(): array
    {
        return [
            'first client error as info'     => [400, LogLevel::INFO],
            'forbidden as info'              => [403, LogLevel::INFO],
            'not found as info'              => [404, LogLevel::INFO],
            'unconfigured client error'      => [410, LogLevel::INFO],
            'last client error as info'      => [499, LogLevel::INFO],
            'internal server error as error' => [500, LogLevel::ERROR],
            'service unavailable as error'   => [503, LogLevel::ERROR],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonHtmlContentTypeProvider(): array
    {
        return [
            'json'         => ['application/json'],
            'problem json' => ['application/problem+json'],
            'xml'          => ['application/xml'],
            'plain text'   => ['text/plain; charset=utf-8'],
            'image'        => ['image/png'],
        ];
    }

    /**
     * @return array<string, array{int}>
     */
    public static function successfulStatusProvider(): array
    {
        return [
            'ok'                 => [200],
            'no content'         => [204],
            'moved permanently'  => [301],
            'not modified'       => [304],
            'last before errors' => [399],
        ];
    }

    /**
     * @return array<string, array{ResponseInterface}>
     */
    public static function untouchedResponseProvider(): array
    {
        return [
            'success'            => [new HtmlResponse('ok')],
            'no page for status' => [new HtmlResponse('', 410)],
            'json error'         => [new JsonResponse(['error' => 'missing'], 404)],
        ];
    }

    #[Test]
    public function clearsViewStateBeforeRenderingThePage(): void
    {
        $reset      = new CountingViewStateReset();
        $middleware = new ErrorPageMiddleware($this->repository, $this->renderer, viewStateReset: $reset);

        $this->process($middleware, new HtmlResponse('', 404));

        static::assertSame(1, $reset->resets);
    }

    #[Test]
    public function doesNotLogASuccessfulResponse(): void
    {
        $logger     = new InMemoryLogger();
        $middleware = new ErrorPageMiddleware($this->repository, $this->renderer, logger: $logger);

        $this->process($middleware, new HtmlResponse('', 200));

        static::assertSame([], $logger->records);
    }

    #[Test]
    public function dropsTheContentLengthOfTheOriginalBody(): void
    {
        $response = $this->process($this->middleware(), new HtmlResponse('Not Found', 404, ['Content-Length' => '9']));

        static::assertFalse($response->hasHeader('Content-Length'));
    }

    #[Test]
    public function keepsOtherHeaders(): void
    {
        $response = $this->process($this->middleware(), new HtmlResponse('', 403, ['X-Request-Id' => 'abc123']));

        static::assertSame('abc123', $response->getHeaderLine('X-Request-Id'));
    }

    #[Test]
    #[DataProvider('configuredErrorStatusProvider')]
    public function keepsTheStatusCode(int $status): void
    {
        $response = $this->process($this->middleware(), new HtmlResponse('', $status));

        static::assertSame($status, $response->getStatusCode());
    }

    #[Test]
    public function leavesTheLayoutToTheRendererByDefault(): void
    {
        $this->process($this->middleware(), new HtmlResponse('', 404));

        static::assertArrayNotHasKey('layout', (array) $this->renderer->renderedParams);
    }

    #[Test]
    #[DataProvider('untouchedResponseProvider')]
    public function leavesViewStateAloneWhenThePageIsNotRendered(ResponseInterface $response): void
    {
        $reset      = new CountingViewStateReset();
        $middleware = new ErrorPageMiddleware($this->repository, $this->renderer, viewStateReset: $reset);

        $this->process($middleware, $response);

        static::assertSame(0, $reset->resets);
    }

    #[Test]
    #[DataProvider('loggedStatusProvider')]
    public function logsAnErrorStatus(int $status, string $level): void
    {
        $logger     = new InMemoryLogger();
        $middleware = new ErrorPageMiddleware($this->repository, $this->renderer, logger: $logger);

        $this->process($middleware, new HtmlResponse('', $status));

        static::assertSame(
            [['level' => $level, 'message' => "HTTP {$status} at " . self::URI, 'context' => []]],
            $logger->records,
        );
    }

    #[Test]
    public function logsANonHtmlError(): void
    {
        $logger     = new InMemoryLogger();
        $middleware = new ErrorPageMiddleware($this->repository, $this->renderer, logger: $logger);

        $this->process($middleware, new JsonResponse([], 404));

        static::assertCount(1, $logger->records);
    }

    #[Test]
    public function logsInDebugMode(): void
    {
        $logger     = new InMemoryLogger();
        $middleware = new ErrorPageMiddleware(
            $this->repository,
            $this->renderer,
            logger: $logger,
            options: new ErrorPageOptions(debug: true),
        );

        $this->process($middleware, new HtmlResponse('', 500));

        static::assertCount(1, $logger->records);
    }

    #[Test]
    public function marksTheRenderedPageAsNotCacheable(): void
    {
        $response = $this->process($this->middleware(), new HtmlResponse('', 404, ['Cache-Control' => 'public']));

        static::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    #[DataProvider('layoutProvider')]
    public function passesAConfiguredLayoutToTheRenderer(string|false $layout): void
    {
        $middleware = new ErrorPageMiddleware(
            $this->repository,
            $this->renderer,
            options: new ErrorPageOptions(layout: $layout),
        );

        $this->process($middleware, new HtmlResponse('', 404));

        static::assertSame($layout, ((array) $this->renderer->renderedParams)['layout'] ?? null);
    }

    #[Test]
    #[DataProvider('configuredErrorStatusProvider')]
    public function rendersTheConfiguredPageForAnErrorStatus(int $status): void
    {
        $this->process($this->middleware(), new HtmlResponse('', $status));

        static::assertSame(
            [
                'status' => $status,
                'title'  => $this->repository->get($status)?->title,
                'body'   => $this->repository->get($status)?->body,
            ],
            $this->renderer->renderedParams,
        );
    }

    #[Test]
    public function rendersTheConfiguredTemplate(): void
    {
        $middleware = new ErrorPageMiddleware(
            $this->repository,
            $this->renderer,
            options: new ErrorPageOptions(viewTemplate: 'error::site'),
        );

        $this->process($middleware, new HtmlResponse('', 404));

        static::assertSame('error::site', $this->renderer->renderedTemplate);
    }

    #[Test]
    public function rendersTheDefaultTemplate(): void
    {
        $this->process($this->middleware(), new HtmlResponse('', 404));

        static::assertSame('contenir-errors::fault', $this->renderer->renderedTemplate);
    }

    #[Test]
    #[DataProvider('htmlContentTypeProvider')]
    public function rendersThePageForAnHtmlContentType(string $contentType): void
    {
        $response = $this->process($this->middleware(), new Response(
            status: 404,
            headers: [
                'Content-Type' => $contentType,
            ],
        ));

        static::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function replacesTheBodyWithTheRenderedTemplate(): void
    {
        $response = $this->process($this->middleware(), new HtmlResponse('<p>Framework 404</p>', 404));

        static::assertSame(
            $this->renderer->render('contenir-errors::fault', [
                'status' => 404,
                'title'  => 'Not found',
                'body'   => '<p>Try the <a href="/">home page</a>.</p>',
            ]),
            (string) $response->getBody(),
        );
    }

    #[Test]
    public function returnsAJsonErrorUntouched(): void
    {
        $response = new JsonResponse(['error' => 'Not found'], 404);

        static::assertSame($response, $this->process($this->middleware(), $response));
    }

    #[Test]
    #[DataProvider('nonHtmlContentTypeProvider')]
    public function returnsResponseUntouchedForANonHtmlContentType(string $contentType): void
    {
        $response = new Response(
            status: 404,
            headers: ['Content-Type' => $contentType],
        );

        static::assertSame($response, $this->process($this->middleware(), $response));
    }

    #[Test]
    public function returnsResponseUntouchedInDebugMode(): void
    {
        $middleware = new ErrorPageMiddleware(
            $this->repository,
            $this->renderer,
            options: new ErrorPageOptions(debug: true),
        );
        $response = new HtmlResponse('<h1>Whoops</h1>', 500);

        static::assertSame($response, $this->process($middleware, $response));
    }

    #[Test]
    public function returnsResponseUntouchedWhenNoPageIsConfiguredForTheStatus(): void
    {
        $response = new HtmlResponse('<p>Gone</p>', 410);

        static::assertSame($response, $this->process($this->middleware(), $response));
    }

    #[Test]
    #[DataProvider('successfulStatusProvider')]
    public function returnsResponseUntouchedWhenStatusIsBelow400(int $status): void
    {
        $response = new HtmlResponse('<p>Fine</p>', $status);

        static::assertSame($response, $this->process($this->middleware(), $response));
    }

    #[Test]
    public function returnsResponseUntouchedWhenTheConfiguredPageIsEmpty(): void
    {
        $middleware = new ErrorPageMiddleware(new InMemoryRepository([new ErrorPage(404, '', '')]), $this->renderer);
        $response   = new HtmlResponse('<p>Framework 404</p>', 404);

        static::assertSame($response, $this->process($middleware, $response));
    }

    #[Test]
    public function setsAnHtmlContentType(): void
    {
        $response = $this->process($this->middleware(), new Response(status: 500));

        static::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    protected function setUp(): void
    {
        $this->renderer   = new FakeTemplateRenderer();
        $this->repository = new InMemoryRepository([
            new ErrorPage(403, 'Not allowed', '<p>Members only.</p>'),
            new ErrorPage(404, 'Not found', '<p>Try the <a href="/">home page</a>.</p>'),
            new ErrorPage(500, 'Site error', '<p>Please try again.</p>'),
        ]);
    }

    private function middleware(): ErrorPageMiddleware
    {
        return new ErrorPageMiddleware($this->repository, $this->renderer);
    }

    private function process(ErrorPageMiddleware $middleware, ResponseInterface $response): ResponseInterface
    {
        return $middleware->process(
            new ServerRequest(
                uri: self::URI,
                method: 'GET',
            ),
            new FixedResponseHandler($response),
        );
    }
}
