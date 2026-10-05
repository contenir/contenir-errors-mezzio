<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\Test\Unit;

use Contenir\Errors\Mezzio\ConfigProvider;
use Contenir\Errors\Mezzio\ErrorPageMiddleware;
use Contenir\Errors\Mezzio\Factory\ErrorPageMiddlewareFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function realpath;

#[Group('unit')]
final class ConfigProviderTest extends TestCase
{
    public function testRegistersTheMiddlewareFactory(): void
    {
        self::assertSame(
            [ErrorPageMiddleware::class => ErrorPageMiddlewareFactory::class],
            (new ConfigProvider())()['dependencies']['factories'],
        );
    }

    public function testRegistersTheTemplateNamespaceAtTheBundledTemplates(): void
    {
        $paths = (new ConfigProvider())()['templates']['paths']['contenir-errors'];

        self::assertSame([realpath(__DIR__ . '/../../templates')], [realpath($paths[0])]);
    }
}
