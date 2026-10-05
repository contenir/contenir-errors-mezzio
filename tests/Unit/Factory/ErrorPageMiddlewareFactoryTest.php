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

    public function testCreatesTheMiddlewareWithoutAConfigService(): void
    {
        self::assertInstanceOf(ErrorPageMiddleware::class, (new ErrorPageMiddlewareFactory())($this->container));
    }

    public function testLeavesTheLayoutToTheRendererByDefault(): void
    {
        $this->process($this->create(), 404);

        self::assertArrayNotHasKey('layout', (array) $this->renderer->renderedParams);
    }

    public function testLeavesTheResponseUntouchedWhenDebugIsOn(): void
    {
        $response = $this->process($this->create(['debug' => true]), 404);

        self::assertSame('', (string) $response->getBody());
    }

    public function testLogsToTheConfiguredLoggerService(): void
    {
        $logger = new InMemoryLogger();
        $this->container->setService('log.psr3', $logger);

        $this->process($this->create(['logger' => 'log.psr3']), 404);

        self::assertCount(1, $logger->records);
    }

    #[DataProvider('layoutProvider')]
    public function testPassesTheConfiguredLayoutToTheRenderer(string|false $layout): void
    {
        $this->process($this->create(['layout' => $layout]), 404);

        self::assertSame($layout, ((array) $this->renderer->renderedParams)['layout'] ?? null);
    }

    public function testRejectsALoggerServiceThatIsNotALogger(): void
    {
        $this->container->setService('config', ['errors' => ['logger' => 'not.a.logger']]);
        $this->container->setService('not.a.logger', new stdClass());

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(
            'logger service "not.a.logger" must implement Psr\Log\LoggerInterface, got stdClass',
        );

        (new ErrorPageMiddlewareFactory())($this->container);
    }

    #[DataProvider('invalidFileProvider')]
    public function testRejectsAnInvalidFileWhenNoRepositoryIsRegistered(mixed $file): void
    {
        $container = new InMemoryContainer([
            TemplateRendererInterface::class => $this->renderer,
            'config'                         => ['errors' => ['file' => $file]],
        ]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('config[errors][file] must be a non-empty string');

        (new ErrorPageMiddlewareFactory())($container);
    }

    #[DataProvider('invalidErrorsConfigProvider')]
    public function testRejectsInvalidConfiguration(mixed $errors, string $message): void
    {
        $this->container->setService('config', ['errors' => $errors]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        (new ErrorPageMiddlewareFactory())($this->container);
    }

    public function testRendersPagesFromTheRegisteredRepository(): void
    {
        $this->process($this->create(), 404);

        self::assertSame('Not found', ((array) $this->renderer->renderedParams)['title'] ?? null);
    }

    public function testRendersTheConfiguredTemplate(): void
    {
        $this->process($this->create(['view_template' => 'error::fault']), 404);

        self::assertSame('error::fault', $this->renderer->renderedTemplate);
    }

    public function testRendersTheDefaultTemplateWhenNoneIsConfigured(): void
    {
        $this->process($this->create(), 404);

        self::assertSame('contenir-errors::fault', $this->renderer->renderedTemplate);
    }

    public function testRendersThePageWhenDebugIsOff(): void
    {
        $response = $this->process($this->create(['debug' => false]), 404);

        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testTreatsANonArrayConfigServiceAsEmpty(): void
    {
        $this->container->setService('config', 'not an array');

        $this->process((new ErrorPageMiddlewareFactory())($this->container), 404);

        self::assertSame(ErrorPageOptions::DEFAULT_VIEW_TEMPLATE, $this->renderer->renderedTemplate);
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
