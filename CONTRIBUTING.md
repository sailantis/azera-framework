# Contributing

This guide covers the development workflow for Azera.

## Requirements

- PHP 8.2 or newer with `ext-mbstring` and `ext-pdo`
- Composer

## Setup

```bash
composer install
```

The lock file uses the released `sailantis/clarity-engine` archive, so installation
does not require a sibling `../clarity-engine` checkout. To develop against a local
Clarity Engine checkout, select its `dev-main` version explicitly:

```bash
composer update "sailantis/clarity-engine:dev-main"
```

Because the path repository is non-canonical, this command is required to select the
local version. A plain `composer update` returns to the locked release. Do not commit
the local `dev-main` path entry; `composer.lock` must retain the `v0.6.0` archive so
CI and other checkouts can install dependencies.

## Running the tests

```bash
composer test
```

or directly:

```bash
php vendor/bin/phpunit
```

Run a single file while iterating:

```bash
php vendor/bin/phpunit tests/Orm/EntityManagerTest.php
```

The suite covers routing, dispatch, ORM and query builder, validation, security, CLI,
events, cache, queue, and AOP. Some ORM tests use SQLite; database-specific tests skip
when their driver or extension is unavailable.

### Optional dependencies

Integration tests skip when their optional packages are unavailable:

- `mongodb/mongodb` (+ `ext-mongodb`) for the live MongoDB store test.
- `twig/twig`, `league/plates`, `illuminate/view` and `spiral/stempler-bridge`
  for the view-engine adapter tests.

These packages are in `require-dev` and install by default. Use
`composer install --no-dev` to omit development dependencies.

## Code style

- Follow surrounding naming, comment, indentation, and formatting conventions
  (four spaces; PSR-12 style).
- Add tests for behavior changes under `tests/`, following the `src/` structure.

## Documentation

- User guides are in `docs/`, numbered by topic.
- The API reference in `docs/api/` is generated:

  ```bash
  php scripts/generate-api-docs.php
  ```

  Do not edit generated files by hand.

## Submitting changes

- Keep commits focused and explain their purpose in the message.
- Run `composer test` and `composer validate --strict` before submitting.
- Open a pull request against `main`.

## Reporting bugs

Report bugs at <https://github.com/sailantis/azera-framework/issues>. Include a
minimal reproduction and the exact error output.
