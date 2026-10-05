<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\LaminasView;

use Contenir\Errors\Mezzio\ViewStateResetInterface;
use Laminas\View\Helper\Placeholder\Container\AbstractStandalone;
use Laminas\View\HelperPluginManager;
use Override;
use Psr\Container\ContainerExceptionInterface;

/**
 * Empties laminas-view's head and script placeholders before the error page renders
 *
 * @api
 */
final readonly class PlaceholderReset implements ViewStateResetInterface
{
    public const array HELPERS = ['headTitle', 'headMeta', 'headLink', 'headScript', 'headStyle', 'inlineScript'];

    public function __construct(
        private HelperPluginManager $helpers,
    ) {}

    private static function clear(mixed $helper): void
    {
        if ($helper instanceof AbstractStandalone) {
            $helper->deleteContainer();
        }
    }

    /**
     * @throws ContainerExceptionInterface When a placeholder helper cannot be built.
     */
    #[Override]
    public function reset(): void
    {
        foreach (self::HELPERS as $name) {
            self::clear($this->helpers->get($name));
        }
    }
}
