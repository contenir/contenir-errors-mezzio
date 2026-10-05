<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\Tests\Unit\Factory;

use Contenir\Errors\ErrorPage;
use Contenir\Errors\ErrorPageRepositoryInterface;
use Contenir\Errors\Mezzio\ErrorPageMiddleware;
use Contenir\Errors\Mezzio\ErrorPageOptions;
use Contenir\Errors\Mezzio\Exception\InvalidConfigurationException;
use Contenir\Errors\Mezzio\Factory\ErrorPageMiddlewareFactory;
use Contenir\Errors\Mezzio\Tests\TestAsset\FakeTemplateRenderer;
use Contenir\Errors\Mezzio\Tests\TestAsset\FixedResponseHandler;
use Contenir\Errors\Mezzio\Tests\TestAsset\InMemoryContainer;
use Contenir\Errors\Mezzio\Tests\TestAsset\InMemoryLogger;
use Contenir\Errors\Repository\InMemoryRepository;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\ServerRequest;
use Mezzio\Template\TemplateRendererInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use stdClass;

#[Group('unit')]
#[Group('factory')]
final class ErrorPageMiddlewareFactoryTest extends TestCase
{
    private FakeTemplateRenderer $renderer;

    private InMemoryContainer $container;

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidErrorsConfigProvider(): array
    {
        return [
            'errors is not an array'   => ['yes', 'config[errors] must be an array'],
            'view template is invalid' => [['view_template' => ''], 'config[errors][view_template] must be'],
            'logger is empty'          => [['logger' => ''], 'config[errors][logger] must be'],
            'logger is not a string'   => [['logger' => 42], 'config[errors][logger] must be'],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidFileProvider(): array
    {
        return [
            'empty string' => [''],
            'integer'      => [42],
            'array'        => [['errors.local.php']],
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

    #[Test]
    public function createsTheMiddlewareWithoutAConfigService(): void
    {
        static::assertInstanceOf(ErrorPageMiddleware::class, (new ErrorPageMiddlewareFactory())($this->container));
    }

    #[Test]
    public function leavesTheLayoutToTheRendererByDefault(): void
    {
        $this->process($this->create(), 404);

        static::assertArrayNotHasKey('layout', (array) $this->renderer->renderedParams);
    }

    #[Test]
    public function leavesTheResponseUntouchedWhenDebugIsOn(): void
    {
        $response = $this->process($this->create(['debug' => true]), 404);

        static::assertSame('', (string) $response->getBody());
    }

    #[Test]
    public function logsToTheConfiguredLoggerService(): void
    {
        $logger = new InMemoryLogger();
        $this->container->setService('log.psr3', $logger);

        $this->process($this->create(['logger' => 'log.psr3']), 404);

        static::assertCount(1, $logger->records);
    }

    #[Test]
    #[DataProvider('layoutProvider')]
    public function passesTheConfiguredLayoutToTheRenderer(string|false $layout): void
    {
        $this->process($this->create(['layout' => $layout]), 404);

        static::assertSame($layout, ((array) $this->renderer->renderedParams)['layout'] ?? null);
    }

    #[Test]
    public function rejectsALoggerServiceThatIsNotALogger(): void
    {
        $this->container->setService('config', ['errors' => ['logger' => 'not.a.logger']]);
        $this->container->setService('not.a.logger', new stdClass());

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(
            'logger service "not.a.logger" must implement Psr\Log\LoggerInterface, got stdClass',
        );

        (new ErrorPageMiddlewareFactory())($this->container);
    }

    #[Test]
    #[DataProvider('invalidFileProvider')]
    public function rejectsAnInvalidFileWhenNoRepositoryIsRegistered(mixed $file): void
    {
        $container = new InMemoryContainer([
            TemplateRendererInterface::class => $this->renderer,
            'config'                         => ['errors' => ['file' => $file]],
        ]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('config[errors][file] must be a non-empty string');

        (new ErrorPageMiddlewareFactory())($container);
    }

    #[Test]
    #[DataProvider('invalidErrorsConfigProvider')]
    public function rejectsInvalidConfiguration(mixed $errors, string $message): void
    {
        $this->container->setService('config', ['errors' => $errors]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        (new ErrorPageMiddlewareFactory())($this->container);
    }

    #[Test]
    public function rendersPagesFromTheRegisteredRepository(): void
    {
        $this->process($this->create(), 404);

        static::assertSame('Not found', ((array) $this->renderer->renderedParams)['title'] ?? null);
    }

    #[Test]
    public function rendersTheConfiguredTemplate(): void
    {
        $this->process($this->create(['view_template' => 'error::fault']), 404);

        static::assertSame('error::fault', $this->renderer->renderedTemplate);
    }

    #[Test]
    public function rendersTheDefaultTemplateWhenNoneIsConfigured(): void
    {
        $this->process($this->create(), 404);

        static::assertSame('contenir-errors::fault', $this->renderer->renderedTemplate);
    }

    #[Test]
    public function rendersThePageWhenDebugIsOff(): void
    {
        $response = $this->process($this->create(['debug' => false]), 404);

        static::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function treatsANonArrayConfigServiceAsEmpty(): void
    {
        $this->container->setService('config', 'not an array');

        $this->process((new ErrorPageMiddlewareFactory())($this->container), 404);

        static::assertSame(ErrorPageOptions::DEFAULT_VIEW_TEMPLATE, $this->renderer->renderedTemplate);
    }

    protected function setUp(): void
    {
        $this->renderer  = new FakeTemplateRenderer();
        $this->container = new InMemoryContainer([
            TemplateRendererInterface::class    => $this->renderer,
            ErrorPageRepositoryInterface::class => new InMemoryRepository([
                new ErrorPage(404, 'Not found', '<p>Nothing here.</p>'),
            ]),
        ]);
    }

    /**
     * @param array<string, mixed> $errors
     */
    private function create(array $errors = []): ErrorPageMiddleware
    {
        $this->container->setService('config', ['errors' => $errors]);

        return (new ErrorPageMiddlewareFactory())($this->container);
    }

    private function process(ErrorPageMiddleware $middleware, int $status): ResponseInterface
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
