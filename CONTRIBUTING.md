# Contributing to Azera

Thanks for your interest in improving Azera. This document covers the day-to-day
workflow.

## Requirements

- PHP 8.2 or newer with `ext-mbstring` and `ext-pdo`
- Composer

## Setup

```bash
composer install
```

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

The suite covers routing, dispatch, the ORM and query builder, validation,
security, CLI and the event/cache/queue/AOP layers. Some ORM tests run against
SQLite by default; database-specific tests skip when the driver or extension is
missing.

### Optional dependencies

A few tests exercise optional integrations and skip automatically when the
package is absent:

- `mongodb/mongodb` (+ `ext-mongodb`) for the live MongoDB store test.
- `twig/twig`, `league/plates`, `illuminate/view` and `spiral/stempler-bridge`
  for the view-engine adapter tests.

These packages are in `require-dev` and install by default. Use
`composer install --no-dev` to omit them.

## Code style

- Follow the surrounding code; match its naming and comment density.
- Match the 4-space indentation and the existing PSR-12-ish conventions.
- Add a test for every behaviour change. Tests live under `tests/`, mirroring
  the `src/` tree.

## Documentation

- User-facing guides live in `docs/`, numbered by topic.
- The API reference under `docs/api/` is **generated**:

  ```bash
  php scripts/generate-api-docs.php
  ```

  Do not edit `docs/api/` by hand.

## Submitting changes

- Keep commits focused and describe the _why_ in the message.
- Make sure `composer test` is green and `composer validate --strict` passes.
- Open a Pull Request against `main`.

## Reporting bugs

Open an issue at <https://github.com/sailantis/azera-framework/issues> with a
minimal reproduction and the exact error output.
