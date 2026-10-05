<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\Test\Integration;

use Contenir\Errors\ErrorPage;
use Contenir\Errors\Mezzio\ErrorPageMiddleware;
use Contenir\Errors\Mezzio\Test\TestAsset\FakeTemplateRenderer;
use Contenir\Errors\Mezzio\Test\TestAsset\FixedResponseHandler;
use Contenir\Errors\Mezzio\Test\Trait\UsesTemporaryDirectory;
use Contenir\Errors\Repository\FileRepository;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[Group('integration')]
#[Group('middleware')]
final class ErrorPageMiddlewareTest extends TestCase
{
    use UsesTemporaryDirectory;

    private FakeTemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->renderer = new FakeTemplateRenderer();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    public function testRendersAPageReadFromTheErrorsFile(): void
    {
        $file = $this->writeConfigFile('errors.local.php', [
            'errors' => ['pages' => [404 => ['title' => 'Not Found', 'body' => 'Missing or outdated.']]],
        ]);

        $this->process(new FileRepository($file), 404);

        self::assertSame(
            ['status' => 404, 'title' => 'Not Found', 'body' => 'Missing or outdated.'],
            $this->renderer->renderedParams,
        );
    }

    public function testPicksUpAPageSavedAfterTheMiddlewareWasBuilt(): void
    {
        $repository = new FileRepository("{$this->temporaryDirectory}/errors.local.php");
        $middleware = new ErrorPageMiddleware($repository, $this->renderer);

        $repository->save(new ErrorPage(403, 'Not Allowed', '<p>Members only.</p>'));
        $this->processWith($middleware, 403);

        self::assertSame('Not Allowed', ((array) $this->renderer->renderedParams)['title'] ?? null);
    }

    public function testReturnsResponseUntouchedWhenTheErrorsFileIsMissing(): void
    {
        $response = $this->process(new FileRepository("{$this->temporaryDirectory}/errors.local.php"), 500);

        self::assertSame('', (string) $response->getBody());
    }

    private function process(FileRepository $repository, int $status): ResponseInterface
    {
        return $this->processWith(new ErrorPageMiddleware($repository, $this->renderer), $status);
    }

    private function processWith(ErrorPageMiddleware $middleware, int $status): ResponseInterface
    {
        return $middleware->process(
            new ServerRequest(
                uri: 'https://www.example.test/page',
                method: 'GET',
            ),
            new FixedResponseHandler(new HtmlResponse('', $status)),
        );
    }
}
