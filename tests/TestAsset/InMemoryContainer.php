<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\Tests\TestAsset;

use Override;
use Psr\Container\ContainerInterface;
use RuntimeException;

use function array_key_exists;
use function sprintf;

/**
 * A PSR-11 container holding a fixed map of services, for testing factories
 * without mocking.
 */
final class InMemoryContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $services
     */
    public function __construct(
        private array $services = [],
    ) {}

    /**
     * @param string $id
     */
    #[Override]
    public function get($id): mixed
    {
        if (! $this->has($id)) {
            throw new RuntimeException(sprintf('Service not found "%s"', $id));
        }

        return $this->services[$id];
    }

    /**
     * @param string $id
     */
    #[Override]
    public function has($id): bool
    {
        return array_key_exists($id, $this->services);
    }

    public function setService(string $id, mixed $service): void
    {
        $this->services[$id] = $service;
    }
}
