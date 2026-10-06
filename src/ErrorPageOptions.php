<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio;

use Contenir\Errors\Mezzio\Exception\InvalidConfigurationException;

use function is_bool;
use function is_string;

/**
 * How ErrorPageMiddleware renders a page: which template, inside which layout,
 * and whether debug mode leaves error responses alone.
 *
 * @api
 */
final readonly class ErrorPageOptions
{
    public const string DEFAULT_VIEW_TEMPLATE = 'contenir-errors::fault';

    /**
     * @param string|false|null $layout Layout to render the page in: null leaves the
     *                                  renderer's default layout in place, false
     *                                  renders the template without a layout
     */
    public function __construct(
        public string $viewTemplate = self::DEFAULT_VIEW_TEMPLATE,
        public string|false|null $layout = null,
        public bool $debug = false,
    ) {}

    /**
     * Builds the options from the `view_template`, `layout` and `debug` keys of
     * config['errors'], applying the defaults for any that are absent.
     *
     * @param array<array-key, mixed> $errors
     *
     * @throws InvalidConfigurationException When a value has the wrong type.
     */
    public static function fromConfig(array $errors): self
    {
        return new self(
            viewTemplate: self::viewTemplate($errors),
            layout: self::layout($errors),
            debug: self::debug($errors),
        );
    }

    /**
     * @param array<array-key, mixed> $errors
     *
     * @throws InvalidConfigurationException When the value is not a boolean.
     */
    private static function debug(array $errors): bool
    {
        if (null === ($errors['debug'] ?? null)) {
            return false;
        }

        if (! is_bool($errors['debug'])) {
            throw new InvalidConfigurationException(
                'contenir/contenir-errors-mezzio: config[errors][debug] must be a boolean.',
            );
        }

        return $errors['debug'];
    }

    /**
     * @param array<array-key, mixed> $errors
     *
     * @throws InvalidConfigurationException When the value is neither null, false nor a non-empty string.
     */
    private static function layout(array $errors): string|false|null
    {
        if (null === ($errors['layout'] ?? null)) {
            return null;
        }

        if (false === $errors['layout']) {
            return false;
        }

        if (is_string($errors['layout']) && '' !== $errors['layout']) {
            return $errors['layout'];
        }

        throw new InvalidConfigurationException(
            'contenir/contenir-errors-mezzio: config[errors][layout] must be null, false or a layout template name.',
        );
    }

    /**
     * @param array<array-key, mixed> $errors
     *
     * @throws InvalidConfigurationException When the value is not a non-empty string.
     */
    private static function viewTemplate(array $errors): string
    {
        if (null === ($errors['view_template'] ?? null)) {
            return self::DEFAULT_VIEW_TEMPLATE;
        }

        if (! is_string($errors['view_template']) || '' === $errors['view_template']) {
            throw new InvalidConfigurationException(
                'contenir/contenir-errors-mezzio: config[errors][view_template] must be a non-empty string.',
            );
        }

        return $errors['view_template'];
    }
}
