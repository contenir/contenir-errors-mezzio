<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\Test\Integration\Factory;

use Contenir\Errors\Mezzio\ErrorPageMiddleware;
use Contenir\Errors\Mezzio\Factory\ErrorPageMiddlewareFactory;
use Contenir\Errors\Mezzio\Test\TestAsset\FakeTemplateRenderer;
use Contenir\Errors\Mezzio\Test\TestAsset\FixedResponseHandler;
use Contenir\Errors\Mezzio\Test\TestAsset\InMemoryContainer;
use Contenir\Errors\Mezzio\Test\Trait\UsesTemporaryDirectory;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\ServerRequest;
use Mezzio\Template\TemplateRendererInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function chdir;
use function getcwd;
use function mkdir;

#[Group('integration')]
#[Group('factory')]
final class ErrorPageMiddlewareFactoryTest extends TestCase
{
    use UsesTemporaryDirectory;

    private FakeTemplateRenderer $renderer;

    private string $originalWorkingDirectory;

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

    public function testReadsPagesFromTheConfiguredFile(): void
    {
        $file = $this->writeConfigFile('pages.php', [
            'errors' => ['pages' => [404 => ['title' => 'Not Found', 'body' => '']]],
        ]);

        $this->process($this->create(['file' => $file]));

        self::assertSame('Not Found', ((array) $this->renderer->renderedParams)['title'] ?? null);
    }

    public function testReadsPagesFromTheSiteAutoloadFileByDefault(): void
    {
        mkdir("{$this->temporaryDirectory}/config");
        mkdir("{$this->temporaryDirectory}/config/autoload");
        $this->writeConfigFile('config/autoload/errors.local.php', [
            'errors' => ['pages' => [404 => ['title' => 'Default location', 'body' => '']]],
        ]);
        chdir($this->temporaryDirectory);

        $this->process($this->create([]));

        self::assertSame('Default location', ((array) $this->renderer->renderedParams)['title'] ?? null);
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
