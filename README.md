# contenir/errors-mezzio

Mezzio (PSR-15) adapter for [`contenir/errors`](https://github.com/contenir/errors).
The sibling of [`contenir/errors-laminas-mvc`](https://github.com/contenir/errors-laminas-mvc).

Re-renders 4xx/5xx HTML responses with the admin-authored page for their
status. Non-invasive on first install — when the admin hasn't authored a
page for a given status, the response Mezzio produced passes through
unchanged.

## Install

```bash
composer require contenir/errors-mezzio
```

`laminas/laminas-component-installer` adds the `ConfigProvider` to
`config/config.php` for you. Without it, add it by hand:

```php
// config/config.php

$aggregator = new ConfigAggregator([
    \Contenir\Errors\Mezzio\ConfigProvider::class,
    // ...
]);
```

The `ConfigProvider` registers the `ErrorPageMiddleware` factory and a
`contenir-errors` template namespace pointing at the bundled
`templates/` directory.

## Pipe it first

Pipe `ErrorPageMiddleware` as the outermost middleware, *before* Mezzio's
`ErrorHandler`:

```php
// config/pipeline.php

use Contenir\Errors\Mezzio\ErrorPageMiddleware;
use Laminas\Stratigility\Middleware\ErrorHandler;
use Mezzio\Handler\NotFoundHandler;

return function (Application $app, MiddlewareFactory $factory, ContainerInterface $container): void {
    $app->pipe(ErrorPageMiddleware::class);
    $app->pipe(ErrorHandler::class);
    // ... routing, dispatch ...
    $app->pipe(NotFoundHandler::class);
};
```

The middleware acts on the response on its way *out*, so it has to sit
outside everything that can produce an error status:

- the `NotFoundHandler`'s 404 for an unmatched route,
- the 500 the `ErrorHandler` generates from an uncaught exception,
- a 403 (or any other 4xx/5xx) returned by a handler.

Piped after the `ErrorHandler`, it would never see the 500: the exception
would pass straight through it and be turned into a response further out.

## Configure

Every key is optional:

```php
// config/autoload/errors.global.php

return [
    'errors' => [
        'view_template' => 'contenir-errors::fault', // override to use a Site-owned template
        'layout'        => null,                     // null = renderer default, false = no layout
        'logger'        => 'log.psr3',               // optional PSR-3 service name
        'debug'         => false,                    // true = leave error responses alone
        'file'          => getcwd() . '/config/autoload/errors.local.php',
    ],
];
```

### `file`

The PHP file the Contenir admin writes the pages to, through
`Contenir\Errors\Repository\FileRepository`. Defaults to
`config/autoload/errors.local.php` under the working directory, which
is the site root for a Mezzio app served from `public/index.php`:

```php
return [
    'errors' => [
        'pages' => [
            404 => ['title' => 'Not Found', 'body' => 'The page is missing or outdated.'],
            500 => ['title' => 'Site Error', 'body' => 'Please try again in a moment.'],
        ],
    ],
];
```

The middleware reads this file on each error response rather than taking
`errors.pages` from the merged config. That is deliberate: Mezzio caches
the merged config in production (`config/autoload/*` is aggregated once
into `data/cache/config-cache.php`), so pages read from config would not
change until that cache was cleared, and an editor's save in the admin
would appear to do nothing. Reading the file costs one `include` per
error response, which opcache serves from memory; the admin's writer
invalidates the opcache entry on save. If the site runs in a separate PHP
pool from the admin with `opcache.validate_timestamps=0`, that
invalidation does not reach the site's pool, so reset its opcache (or
leave timestamp validation on) for saves to show.

To read the pages from somewhere else, register a service for
`Contenir\Errors\ErrorPageRepositoryInterface`. When one is registered
it is used instead and `file` is ignored.

### `logger`

`logger` may be `null` or the name of a container service implementing
`Psr\Log\LoggerInterface`. Every 4xx/5xx is logged whether or not a page
is configured for it: `info()` for 4xx, `error()` for 5xx, with the
message `HTTP <status> at <uri>`. These are the same levels and message
as the Laminas MVC adapter. The exception behind a 500 is not available
here, because the `ErrorHandler` inside has already turned it into a
response; log exceptions with an `ErrorHandler` listener if you need
them.

### `debug`

`debug` (default `false`) is the development escape hatch. When `true`
the middleware still logs, but returns every response untouched, so
Whoops (or the `ErrorHandler`'s own exception output) reaches the browser
instead of the polite admin page. Set it from the development config
alongside Mezzio's own `debug` flag:

```php
// config/development.config.php.dist

return [
    'debug'  => true,
    'errors' => ['debug' => true],
    ConfigAggregator::ENABLE_CACHE => false,
];
```

### What gets re-rendered

The middleware leaves the response alone when:

- the status is below 400,
- `debug` is on,
- the response is not HTML: a `Content-Type` other than `text/html` or
  `application/xhtml+xml`, such as a JSON API error (a response with no
  `Content-Type` counts as HTML, which is what the `ErrorHandler`
  produces),
- there is no page for the status, or the page has neither title nor body.

Otherwise it renders `view_template` with the page and returns a response
that keeps the original status code and headers, with:

- the body replaced by the rendered page,
- any `Content-Length` removed,
- `Content-Type: text/html; charset=utf-8`,
- `Cache-Control: no-store`, so page caches and CDNs do not store the
  error page.

## Override the template

The bundled `contenir-errors::fault` template is a content fragment that
renders inside the site's normal layout. It is written for
`mezzio/mezzio-laminasviewrenderer`. To brand the page beyond what the
body field allows, or to use another renderer, set `errors.view_template`
to a template of your own:

```php
'errors' => [
    'view_template' => 'error::fault',
],
```

Your template receives:

| Variable  | Type     | Notes                                               |
| --------- | -------- | --------------------------------------------------- |
| `$status` | `int`    | HTTP status code (e.g. 404)                         |
| `$title`  | `string` | Plain text written by the admin — escape it         |
| `$body`   | `string` | Sanitized HTML fragment (inline only) — render raw  |

The body is *trusted* — sanitization is the writer's responsibility (the
admin passes it through an inline-HTML sanitizer before saving). Render
it with `<?= $body ?>`.

### Layout

`layout` controls the layout the template renders in:

- `null` (default) passes no `layout` parameter, so the renderer uses its
  default layout — the site's normal one.
- A template name, e.g. `'layout::error'`, renders the page in that layout.
- `false` renders the template on its own. The bundled template is a
  fragment, so pair `false` with a `view_template` of your own that
  outputs a complete document.

## Leftover view state

The admin's page usually renders after the site has already rendered its own (the
NotFoundHandler's 404, the ErrorHandler's 500) in the same request. laminas-view's
head and script helpers keep what that first render added, so the error page would
repeat its title, meta tags and scripts. When the container has
`Laminas\View\HelperPluginManager` (any mezzio-laminasviewrenderer site), the factory
wires `LaminasView\PlaceholderReset`, which empties `headTitle`, `headMeta`,
`headLink`, `headScript`, `headStyle` and `inlineScript` just before the page renders.
Another engine with the same problem can pass its own `ViewStateResetInterface` to the
middleware.

## License

MIT. See [LICENSE](LICENSE).
