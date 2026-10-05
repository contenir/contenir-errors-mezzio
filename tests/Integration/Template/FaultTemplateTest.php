<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\Tests\Integration\Template;

use Laminas\View\Model\ViewModel;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\View\Resolver\TemplateMapResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Renders the bundled templates/fault.phtml through laminas-view, the renderer
 * Mezzio sites use with mezzio-laminasviewrenderer.
 */
#[Group('integration')]
#[Group('template')]
final class FaultTemplateTest extends TestCase
{
    /**
     * @return array<string, array{int}>
     */
    public static function statusProvider(): array
    {
        return [
            'forbidden'             => [403],
            'not found'             => [404],
            'internal server error' => [500],
        ];
    }

    #[Test]
    public function escapesTheTitle(): void
    {
        $html = $this->render(404, '<script>alert(1)</script> & co', '');

        static::assertStringContainsString(
            '<h1 class="fault__title">&lt;script&gt;alert(1)&lt;/script&gt; &amp; co</h1>',
            $html,
        );
    }

    #[Test]
    #[DataProvider('statusProvider')]
    public function namesTheStatus(int $status): void
    {
        $html = $this->render($status, 'Title', '');

        static::assertStringContainsString(
            "<section class=\"fault fault--{$status}\">
    <p class=\"fault__status\">Error {$status}</p>",
            $html,
        );
    }

    #[Test]
    public function omitsTheBodyWhenItIsEmpty(): void
    {
        static::assertStringNotContainsString('fault__body', $this->render(500, 'Title', ''));
    }

    #[Test]
    public function omitsTheTitleWhenItIsEmpty(): void
    {
        static::assertStringNotContainsString('fault__title', $this->render(500, '', '<p>Body</p>'));
    }

    #[Test]
    public function rendersTheBodyAsAuthoredHtml(): void
    {
        $html = $this->render(404, 'Not found', '<p>Try the <a href="/">home page</a>.</p>');

        static::assertStringContainsString(
            '<div class="fault__body"><p>Try the <a href="/">home page</a>.</p></div>',
            $html,
        );
    }

    private function render(int $status, string $title, string $body): string
    {
        $renderer = new PhpRenderer();
        $renderer->setResolver(new TemplateMapResolver([
            'contenir-errors::fault' => __DIR__ . '/../../../templates/fault.phtml',
        ]));

        $model = new ViewModel(['status' => $status, 'title' => $title, 'body' => $body]);
        $model->setTemplate('contenir-errors::fault');

        return $renderer->render($model);
    }
}
