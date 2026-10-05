<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\Tests\Integration\LaminasView;

use Contenir\Errors\Mezzio\LaminasView\PlaceholderReset;
use Laminas\View\Helper\HeadMeta;
use Laminas\View\Helper\HeadTitle;
use Laminas\View\Helper\InlineScript;
use Laminas\View\Renderer\PhpRenderer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function count;

#[Group('integration')]
final class PlaceholderResetTest extends TestCase
{
    public function testEmptiesTheHeadAndScriptPlaceholders(): void
    {
        $helpers = (new PhpRenderer())->getHelperPluginManager();
        $helpers->get(HeadTitle::class)->append('Page not found');
        $helpers->get(HeadMeta::class)->appendName('description', 'An earlier page');
        $helpers->get(InlineScript::class)->appendScript('console.log(1);');

        (new PlaceholderReset($helpers))->reset();

        self::assertSame([0, 0, 0], [
            count($helpers->get(HeadTitle::class)->getContainer()),
            count($helpers->get(HeadMeta::class)->getContainer()),
            count($helpers->get(InlineScript::class)->getContainer()),
        ]);
    }
}
