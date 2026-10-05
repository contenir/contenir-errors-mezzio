<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\Exception;

use RuntimeException;

/**
 * Raised by the factory when config['errors'], or a service it names, has the
 * wrong type. Thrown at container build time, so a misconfigured site fails
 * on its first request rather than on its first error.
 *
 * @api
 */
final class InvalidConfigurationException extends RuntimeException {}
