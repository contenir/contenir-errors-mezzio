# Upgrading from 0.x to 2.0

2.0 has the same public API as 0.1. Only the platform and dependency
constraints change.

| | 0.x | 2.0 |
| --- | --- | --- |
| PHP | ^8.3 | 8.3, 8.4 or 8.5 |
| `contenir/errors` | ^0.1 | ^0.1.1 or ^2.0 |
| `contenir/config` | ^0.1 | ^0.2 or ^2.0 |

To upgrade, update the constraint:

```bash
composer require contenir/errors-mezzio:^2.0
```

No code or configuration changes are needed. `ConfigProvider`,
`ErrorPageMiddleware`, `ErrorPageOptions`, `ViewStateResetInterface`,
`LaminasView\PlaceholderReset`, `Factory\ErrorPageMiddlewareFactory` and
`Exception\InvalidConfigurationException` keep their signatures, constants
and behaviour, and every `config['errors']` key means what it did in 0.1.

## Final classes

Every concrete class is `final`, as it already was in 0.1. To change
behaviour, use the extension points instead of subclassing:

- `ViewStateResetInterface`, passed to `ErrorPageMiddleware`, to clear another
  template engine's leftover state,
- `Contenir\Errors\ErrorPageRepositoryInterface` (registered in the
  container) for a different source of pages,
- `errors.view_template` and `errors.layout` for the markup,
- any PSR-3 logger named by `errors.logger`.

## Dependency floors

`contenir/errors` 0.1.0 is no longer accepted: it reads a flat pages file,
not the `errors.pages` shape the admin writes. `contenir/config` 0.1 is no
longer accepted either. If another package pins either one lower, update that
package (or its constraint) first.

Projects that cannot move yet can stay on `^0.1`, which is maintained on the
`0.x` branch.

## Package renamed in 2.1

From 2.1, the package is published as `contenir/contenir-errors-mezzio`. It declares
`replace` for `contenir/errors-mezzio`, so the two can never be installed together.
Its dependencies move to their renamed packages too: `contenir/contenir-errors`
and `contenir/contenir-config`, both `^2.1`. Switch the requirement:

```bash
composer remove contenir/errors-mezzio && composer require contenir/contenir-errors-mezzio:^2.1
```

No code changes are needed: namespaces and classes are unchanged.
