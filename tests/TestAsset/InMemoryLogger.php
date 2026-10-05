<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\Tests\TestAsset;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A PSR-3 logger that keeps every record in memory for assertions.
 */
final class InMemoryLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /**
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
