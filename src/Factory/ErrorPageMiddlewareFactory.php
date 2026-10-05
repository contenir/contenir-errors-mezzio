<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\Factory;

use Contenir\Errors\ErrorPageRepositoryInterface;
use Contenir\Errors\Mezzio\ErrorPageMiddleware;
use Contenir\Errors\Mezzio\ErrorPageOptions;
use Contenir\Errors\Mezzio\Exception\InvalidConfigurationException;
use Contenir\Errors\Mezzio\LaminasView\PlaceholderReset;
use Contenir\Errors\Mezzio\ViewStateResetInterface;
use Contenir\Errors\Repository\FileRepository;
use Laminas\View\HelperPluginManager;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

use function get_debug_type;
use function getcwd;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Builds the ErrorPageMiddleware from config['errors'].
 *
 * Recognised keys, all optional:
 *
 *     'errors' => [
 *         'view_template' => 'contenir-errors::fault',
 *         'layout'        => null,
 *         'logger'        => null,
 *         'debug'         => false,
 *         'file'          => getcwd() . '/config/autoload/errors.local.php',
 *     ],
 *
 * @api
 */
final class ErrorPageMiddlewareFactory
{
    public const string DEFAULT_FILE = '/config/autoload/errors.local.php';

    /**
     * @throws InvalidConfigurationException When a config['errors'] value, or the logger it names, has the wrong type.
     */
    public function __invoke(ContainerInterface $container): ErrorPageMiddleware
    {
        $errors = $container->has('config') ? $this->errorsConfig($container->get('config')) : [];

        return new ErrorPageMiddleware(
            repository: $this->resolveRepository($container, $errors),
            renderer: $container->get(TemplateRendererInterface::class),
            logger: $this->resolveLogger($container, $errors),
            options: ErrorPageOptions::fromConfig($errors),
            viewStateReset: $this->resolveViewStateReset($container),
        );
    }

    /**
     * Clears laminas-view's placeholders when the site renders with laminas-view
     */
    private function resolveViewStateReset(ContainerInterface $container): ?ViewStateResetInterface
    {
        if (! $container->has(HelperPluginManager::class)) {
            return null;
        }

        return new PlaceholderReset($container->get(HelperPluginManager::class));
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws InvalidConfigurationException When config['errors'] is not an array.
     */
    private function errorsConfig(mixed $config): array
    {
        if (! is_array($config) || null === ($config['errors'] ?? null)) {
            return [];
        }

        if (! is_array($config['errors'])) {
            throw new InvalidConfigurationException('contenir/errors-mezzio: config[errors] must be an array.');
        }

        return $config['errors'];
    }

    /**
     * A repository registered in the container wins. Otherwise the pages are
     * read from the admin's PHP file on every lookup rather than from merged
     * config: Mezzio caches merged config in production, so content the admin
     * saves would not appear until that cache was cleared.
     *
     * @param array<array-key, mixed> $errors
     *
     * @throws InvalidConfigurationException When the file option is not a non-empty string.
     */
    private function resolveRepository(ContainerInterface $container, array $errors): ErrorPageRepositoryInterface
    {
        if ($container->has(ErrorPageRepositoryInterface::class)) {
            return $container->get(ErrorPageRepositoryInterface::class);
        }

        return new FileRepository(self::optionalString($errors, 'file', 'a non-empty string') ?? self::defaultFile());
    }

    /**
     * @param array<array-key, mixed> $errors
     *
     * @throws InvalidConfigurationException When the option is not a service name, or names a non-logger.
     */
    private function resolveLogger(ContainerInterface $container, array $errors): ?LoggerInterface
    {
        $name = self::optionalString($errors, 'logger', 'null or a container service name');
        if (null === $name) {
            return null;
        }

        return $this->asLogger($container->get($name), $name);
    }

    /**
     * @throws InvalidConfigurationException When the service is not a PSR-3 logger.
     */
    private function asLogger(mixed $service, string $name): LoggerInterface
    {
        if (! $service instanceof LoggerInterface) {
            throw new InvalidConfigurationException(sprintf(
                'contenir/errors-mezzio: logger service "%s" must implement %s, got %s.',
                $name,
                LoggerInterface::class,
                get_debug_type($service),
            ));
        }

        return $service;
    }

    /**
     * Reads an optional string option: null when absent or null, the value when
     * it is a non-empty string.
     *
     * @param array<array-key, mixed> $errors
     *
     * @throws InvalidConfigurationException When the option is set to anything else.
     */
    private static function optionalString(array $errors, string $key, string $expected): ?string
    {
        if (null === ($errors[$key] ?? null)) {
            return null;
        }

        if (is_string($errors[$key]) && '' !== $errors[$key]) {
            return $errors[$key];
        }

        throw new InvalidConfigurationException(sprintf(
            'contenir/errors-mezzio: config[errors][%s] must be %s.',
            $key,
            $expected,
        ));
    }

    /**
     * The site's own autoload file, where the Contenir admin writes the pages.
     */
    private static function defaultFile(): string
    {
        $workingDirectory = getcwd();

        return (false === $workingDirectory ? '.' : $workingDirectory) . self::DEFAULT_FILE;
    }
}
