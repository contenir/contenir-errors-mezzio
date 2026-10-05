<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio;

/**
 * Registers the middleware factory and the `contenir-errors` template
 * namespace with a Mezzio application.
 *
 * @api
 */
final class ConfigProvider
{
    public const string TEMPLATE_NAMESPACE = 'contenir-errors';

    /**
     * @return array{factories: array<class-string, class-string>}
     */
    public function getDependencies(): array
    {
        return [
            'factories' => [
                ErrorPageMiddleware::class => Factory\ErrorPageMiddlewareFactory::class,
            ],
        ];
    }

    /**
     * @return array{paths: array<string, list<string>>}
     */
    public function getTemplates(): array
    {
        return [
            'paths' => [
                self::TEMPLATE_NAMESPACE => [__DIR__ . '/../templates'],
            ],
        ];
    }

    /**
     * @return array{
     *     dependencies: array{factories: array<class-string, class-string>},
     *     templates: array{paths: array<string, list<string>>},
     * }
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => $this->getDependencies(),
            'templates'    => $this->getTemplates(),
        ];
    }
}
