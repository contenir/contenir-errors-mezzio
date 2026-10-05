<?php

declare(strict_types=1);

namespace Contenir\Errors\Mezzio;

/**
 * Clears view state left over from a render earlier in the same request
 *
 * The middleware renders the admin's error page after the site has usually rendered
 * its own (the NotFoundHandler's 404, the ErrorHandler's 500). Template engines whose
 * helpers accumulate state, such as laminas-view's head and script placeholders, would
 * otherwise output that earlier page's titles, meta tags and scripts a second time.
 *
 * @api
 */
interface ViewStateResetInterface
{
    public function reset(): void;
}
