<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\Tests\Unit;

use Contenir\Errors\Mezzio\ConfigProvider;
use Contenir\Errors\Mezzio\ErrorPageMiddleware;
use Contenir\Errors\Mezzio\Factory\ErrorPageMiddlewareFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function realpath;

#[Group('unit')]
final class ConfigProviderTest extends TestCase
{
    #[Test]
    public function exposesTheDependenciesForDirectUse(): void
    {
        static::assertSame(
            ['factories' => [ErrorPageMiddleware::class => ErrorPageMiddlewareFactory::class]],
            (new ConfigProvider())->getDependencies(),
        );
    }

    #[Test]
    public function exposesTheTemplatePathsForDirectUse(): void
    {
        $paths = (new ConfigProvider())->getTemplates()['paths'][ConfigProvider::TEMPLATE_NAMESPACE];

        static::assertSame([realpath(__DIR__ . '/../../templates')], [realpath($paths[0])]);
    }

    #[Test]
    public function registersTheMiddlewareFactory(): void
    {
        static::assertSame(
            [ErrorPageMiddleware::class => ErrorPageMiddlewareFactory::class],
            (new ConfigProvider())()['dependencies']['factories'],
        );
    }

    #[Test]
    public function registersTheTemplateNamespaceAtTheBundledTemplates(): void
    {
        $paths = (new ConfigProvider())()['templates']['paths']['contenir-errors'];

        static::assertSame([realpath(__DIR__ . '/../../templates')], [realpath($paths[0])]);
    }
}
