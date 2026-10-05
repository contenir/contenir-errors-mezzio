<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\Tests\Integration\Factory;

use Contenir\Errors\Mezzio\ErrorPageMiddleware;
use Contenir\Errors\Mezzio\Factory\ErrorPageMiddlewareFactory;
use Contenir\Errors\Mezzio\Tests\TestAsset\FakeTemplateRenderer;
use Contenir\Errors\Mezzio\Tests\TestAsset\FixedResponseHandler;
use Contenir\Errors\Mezzio\Tests\TestAsset\InMemoryContainer;
use Contenir\Errors\Mezzio\Tests\Trait\TemporaryDirectoryTrait;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\ServerRequest;
use Mezzio\Template\TemplateRendererInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chdir;
use function getcwd;
use function mkdir;
use function rmdir;

#[Group('integration')]
#[Group('factory')]
final class ErrorPageMiddlewareFactoryTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private FakeTemplateRenderer $renderer;

    private string $originalWorkingDirectory;

    #[Test]
    public function anchorsTheDefaultErrorsFileToTheWorkingDirectoryAtBuildTime(): void
    {
        mkdir("{$this->temporaryDirectory}/config");
        mkdir("{$this->temporaryDirectory}/config/autoload");
        mkdir("{$this->temporaryDirectory}/elsewhere");
        $this->writeConfigFile('config/autoload/errors.local.php', [
            'errors' => ['pages' => [404 => ['title' => 'Build-time location', 'body' => '']]],
        ]);
        chdir($this->temporaryDirectory);
        $middleware = $this->create([]);
        chdir("{$this->temporaryDirectory}/elsewhere");

        $this->process($middleware);

        static::assertSame('Build-time location', ((array) $this->renderer->renderedParams)['title'] ?? null);
    }

    #[Test]
    public function fallsBackToARelativeErrorsFileWhenTheWorkingDirectoryIsGone(): void
    {
        $vanished = "{$this->temporaryDirectory}/vanished";
        mkdir($vanished);
        chdir($vanished);
        rmdir($vanished);

        $this->process($this->create([]));

        static::assertNull($this->renderer->renderedParams);
    }

    #[Test]
    public function readsPagesFromTheConfiguredFile(): void
    {
        $file = $this->writeConfigFile('pages.php', [
            'errors' => ['pages' => [404 => ['title' => 'Not Found', 'body' => '']]],
        ]);

        $this->process($this->create(['file' => $file]));

        static::assertSame('Not Found', ((array) $this->renderer->renderedParams)['title'] ?? null);
    }

    #[Test]
    public function readsPagesFromTheSiteAutoloadFileByDefault(): void
    {
        mkdir("{$this->temporaryDirectory}/config");
        mkdir("{$this->temporaryDirectory}/config/autoload");
        $this->writeConfigFile('config/autoload/errors.local.php', [
            'errors' => ['pages' => [404 => ['title' => 'Default location', 'body' => '']]],
        ]);
        chdir($this->temporaryDirectory);

        $this->process($this->create([]));

        static::assertSame('Default location', ((array) $this->renderer->renderedParams)['title'] ?? null);
    }

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->renderer                 = new FakeTemplateRenderer();
        $this->originalWorkingDirectory = (string) getcwd();
    }

    protected function tearDown(): void
    {
        chdir($this->originalWorkingDirectory);
        $this->tearDownTemporaryDirectory();
    }

    /**
     * @param array<string, mixed> $errors
     */
    private function create(array $errors): ErrorPageMiddleware
    {
        return (new ErrorPageMiddlewareFactory())(new InMemoryContainer([
            TemplateRendererInterface::class => $this->renderer,
            'config'                         => ['errors' => $errors],
        ]));
    }

    private function process(ErrorPageMiddleware $middleware): void
    {
        $middleware->process(
            new ServerRequest(
                uri: 'https://www.example.test/page',
                method: 'GET',
            ),
            new FixedResponseHandler(new HtmlResponse('', 404)),
        );
    }
}
