<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\Tests\TestAsset;

use Mezzio\Template\TemplatePath;
use Mezzio\Template\TemplateRendererInterface;
use Override;

use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * A template renderer that records what it was asked to render and returns a
 * predictable string naming the template and its parameters.
 */
final class FakeTemplateRenderer implements TemplateRendererInterface
{
    public ?string $renderedTemplate = null;

    /** @var array<array-key, mixed>|object|null */
    public array|object|null $renderedParams = null;

    #[Override]
    public function addDefaultParam(string $templateName, string $param, mixed $value): void {}

    #[Override]
    public function addPath(string $path, ?string $namespace = null): void {}

    /**
     * @return list<TemplatePath>
     */
    #[Override]
    public function getPaths(): array
    {
        return [];
    }

    /**
     * @param array<array-key, mixed>|object $params
     */
    #[Override]
    public function render(string $name, $params = []): string
    {
        $this->renderedTemplate = $name;
        $this->renderedParams   = $params;

        return sprintf('<rendered template="%s">%s</rendered>', $name, json_encode($params, JSON_THROW_ON_ERROR));
    }
}
