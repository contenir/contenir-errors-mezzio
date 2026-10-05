<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\Test\Integration\Factory;

use Contenir\Errors\ErrorPage;
use Contenir\Errors\ErrorPageRepositoryInterface;
use Contenir\Errors\Mezzio\Factory\ErrorPageMiddlewareFactory;
use Contenir\Errors\Mezzio\Test\TestAsset\FakeTemplateRenderer;
use Contenir\Errors\Mezzio\Test\TestAsset\FixedResponseHandler;
use Contenir\Errors\Mezzio\Test\TestAsset\InMemoryContainer;
use Contenir\Errors\Repository\InMemoryRepository;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\View\Helper\HeadTitle;
use Laminas\View\HelperPluginManager;
use Laminas\View\Renderer\PhpRenderer;
use Mezzio\Template\TemplateRendererInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function count;

#[Group('integration')]
#[Group('factory')]
final class ViewStateResetWiringTest extends TestCase
{
    public function testClearsLaminasViewPlaceholdersWhenTheHelperManagerIsAvailable(): void
    {
        $helpers = (new PhpRenderer())->getHelperPluginManager();
        $helpers->get(HeadTitle::class)->append('Page not found');

        $middleware = (new ErrorPageMiddlewareFactory())(new InMemoryContainer([
            TemplateRendererInterface::class    => new FakeTemplateRenderer(),
            ErrorPageRepositoryInterface::class => new InMemoryRepository([new ErrorPage(404, 'Not found', '')]),
            HelperPluginManager::class          => $helpers,
        ]));
        $middleware->process(new ServerRequest(), new FixedResponseHandler(new HtmlResponse('', 404)));

        self::assertSame(0, count($helpers->get(HeadTitle::class)->getContainer()));
    }
}
