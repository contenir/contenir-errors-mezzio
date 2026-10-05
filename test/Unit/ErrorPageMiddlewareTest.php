<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\Test\Unit;

use Contenir\Errors\ErrorPage;
use Contenir\Errors\Mezzio\ErrorPageMiddleware;
use Contenir\Errors\Mezzio\ErrorPageOptions;
use Contenir\Errors\Mezzio\Test\TestAsset\FakeTemplateRenderer;
use Contenir\Errors\Mezzio\Test\TestAsset\FixedResponseHandler;
use Contenir\Errors\Mezzio\Test\TestAsset\InMemoryLogger;
use Contenir\Errors\Repository\InMemoryRepository;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
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

    protected function setUp(): void
    {
        $this->renderer   = new FakeTemplateRenderer();
        $this->repository = new InMemoryRepository([
            new ErrorPage(403, 'Not allowed', '<p>Members only.</p>'),
            new ErrorPage(404, 'Not found', '<p>Try the <a href="/">home page</a>.</p>'),
            new ErrorPage(500, 'Site error', '<p>Please try again.</p>'),
        ]);
    }

    #[DataProvider('successfulStatusProvider')]
    public function testReturnsResponseUntouchedWhenStatusIsBelow400(int $status): void
    {
        $response = new HtmlResponse('<p>Fine</p>', $status);

        self::assertSame($response, $this->process($this->middleware(), $response));
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

    #[DataProvider('configuredErrorStatusProvider')]
    public function testRendersTheConfiguredPageForAnErrorStatus(int $status): void
    {
        $this->process($this->middleware(), new HtmlResponse('', $status));

        self::assertSame(
            [
                'status' => $status,
                'title'  => $this->repository->get($status)?->title,
                'body'   => $this->repository->get($status)?->body,
            ],
            $this->renderer->renderedParams,
        );
    }

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

    public function testRendersTheDefaultTemplate(): void
    {
        $this->process($this->middleware(), new HtmlResponse('', 404));

        self::assertSame('contenir-errors::fault', $this->renderer->renderedTemplate);
    }

    public function testRendersTheConfiguredTemplate(): void
    {
        $middleware = new ErrorPageMiddleware(
            $this->repository,
            $this->renderer,
            options: new ErrorPageOptions(viewTemplate: 'error::site'),
        );

        $this->process($middleware, new HtmlResponse('', 404));

        self::assertSame('error::site', $this->renderer->renderedTemplate);
    }

    public function testReplacesTheBodyWithTheRenderedTemplate(): void
    {
        $response = $this->process($this->middleware(), new HtmlResponse('<p>Framework 404</p>', 404));

        self::assertSame(
            $this->renderer->render('contenir-errors::fault', [
                'status' => 404,
                'title'  => 'Not found',
                'body'   => '<p>Try the <a href="/">home page</a>.</p>',
            ]),
            (string) $response->getBody(),
        );
    }

    #[DataProvider('configuredErrorStatusProvider')]
    public function testKeepsTheStatusCode(int $status): void
    {
        $response = $this->process($this->middleware(), new HtmlResponse('', $status));

        self::assertSame($status, $response->getStatusCode());
    }

    public function testKeepsOtherHeaders(): void
    {
        $response = $this->process($this->middleware(), new HtmlResponse('', 403, ['X-Request-Id' => 'abc123']));

        self::assertSame('abc123', $response->getHeaderLine('X-Request-Id'));
    }

    public function testDropsTheContentLengthOfTheOriginalBody(): void
    {
        $response = $this->process($this->middleware(), new HtmlResponse('Not Found', 404, ['Content-Length' => '9']));

        self::assertFalse($response->hasHeader('Content-Length'));
    }

    public function testSetsAnHtmlContentType(): void
    {
        $response = $this->process($this->middleware(), new Response(status: 500));

        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    public function testMarksTheRenderedPageAsNotCacheable(): void
    {
        $response = $this->process($this->middleware(), new HtmlResponse('', 404, ['Cache-Control' => 'public']));

        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testLeavesTheLayoutToTheRendererByDefault(): void
    {
        $this->process($this->middleware(), new HtmlResponse('', 404));

        self::assertArrayNotHasKey('layout', (array) $this->renderer->renderedParams);
    }

    #[DataProvider('layoutProvider')]
    public function testPassesAConfiguredLayoutToTheRenderer(string|false $layout): void
    {
        $middleware = new ErrorPageMiddleware(
            $this->repository,
            $this->renderer,
            options: new ErrorPageOptions(layout: $layout),
        );

        $this->process($middleware, new HtmlResponse('', 404));

        self::assertSame($layout, ((array) $this->renderer->renderedParams)['layout'] ?? null);
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

    public function testReturnsResponseUntouchedWhenNoPageIsConfiguredForTheStatus(): void
    {
        $response = new HtmlResponse('<p>Gone</p>', 410);

        self::assertSame($response, $this->process($this->middleware(), $response));
    }

    public function testReturnsResponseUntouchedWhenTheConfiguredPageIsEmpty(): void
    {
        $middleware = new ErrorPageMiddleware(new InMemoryRepository([new ErrorPage(404, '', '')]), $this->renderer);
        $response   = new HtmlResponse('<p>Framework 404</p>', 404);

        self::assertSame($response, $this->process($middleware, $response));
    }

    #[DataProvider('htmlContentTypeProvider')]
    public function testRendersThePageForAnHtmlContentType(string $contentType): void
    {
        $response = $this->process($this->middleware(), new Response(
            status: 404,
            headers: [
                'Content-Type' => $contentType,
            ],
        ));

        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
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

    #[DataProvider('nonHtmlContentTypeProvider')]
    public function testReturnsResponseUntouchedForANonHtmlContentType(string $contentType): void
    {
        $response = new Response(
            status: 404,
            headers: ['Content-Type' => $contentType],
        );

        self::assertSame($response, $this->process($this->middleware(), $response));
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

    public function testReturnsAJsonErrorUntouched(): void
    {
        $response = new JsonResponse(['error' => 'Not found'], 404);

        self::assertSame($response, $this->process($this->middleware(), $response));
    }

    public function testReturnsResponseUntouchedInDebugMode(): void
    {
        $middleware = new ErrorPageMiddleware(
            $this->repository,
            $this->renderer,
            options: new ErrorPageOptions(debug: true),
        );
        $response = new HtmlResponse('<h1>Whoops</h1>', 500);

        self::assertSame($response, $this->process($middleware, $response));
    }

    #[DataProvider('loggedStatusProvider')]
    public function testLogsAnErrorStatus(int $status, string $level): void
    {
        $logger     = new InMemoryLogger();
        $middleware = new ErrorPageMiddleware($this->repository, $this->renderer, logger: $logger);

        $this->process($middleware, new HtmlResponse('', $status));

        self::assertSame(
            [['level' => $level, 'message' => "HTTP {$status} at " . self::URI, 'context' => []]],
            $logger->records,
        );
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function loggedStatusProvider(): array
    {
        return [
            'forbidden as info'              => [403, LogLevel::INFO],
            'not found as info'              => [404, LogLevel::INFO],
            'unconfigured client error'      => [410, LogLevel::INFO],
            'last client error as info'      => [499, LogLevel::INFO],
            'internal server error as error' => [500, LogLevel::ERROR],
            'service unavailable as error'   => [503, LogLevel::ERROR],
        ];
    }

    public function testLogsInDebugMode(): void
    {
        $logger     = new InMemoryLogger();
        $middleware = new ErrorPageMiddleware(
            $this->repository,
            $this->renderer,
            logger: $logger,
            options: new ErrorPageOptions(debug: true),
        );

        $this->process($middleware, new HtmlResponse('', 500));

        self::assertCount(1, $logger->records);
    }

    public function testLogsANonHtmlError(): void
    {
        $logger     = new InMemoryLogger();
        $middleware = new ErrorPageMiddleware($this->repository, $this->renderer, logger: $logger);

        $this->process($middleware, new JsonResponse([], 404));

        self::assertCount(1, $logger->records);
    }

    public function testDoesNotLogASuccessfulResponse(): void
    {
        $logger     = new InMemoryLogger();
        $middleware = new ErrorPageMiddleware($this->repository, $this->renderer, logger: $logger);

        $this->process($middleware, new HtmlResponse('', 200));

        self::assertSame([], $logger->records);
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
