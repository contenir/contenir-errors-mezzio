<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio\Tests\TestAsset;

use Contenir\Errors\Mezzio\ViewStateResetInterface;
use Override;

/**
 * Counts how often the middleware asks for view state to be cleared
 */
final class CountingViewStateReset implements ViewStateResetInterface
{
    public int $resets = 0;

    #[Override]
    public function reset(): void
    {
        $this->resets++;
    }
}
