# Security Policy

## Supported versions

The latest release on the `main` branch receives security fixes.

## Reporting a vulnerability

Please **do not** open a public issue for a security problem. Instead, report it
privately via GitHub's
[security advisories](https://github.com/sailantis/azera-framework/security/advisories/new),
or email the maintainers.

Include:

- a description of the issue,
- a minimal application or code snippet that reproduces it,
- the Azera version, PHP version, database driver, and
- any output that shows the impact.

We will acknowledge the report and keep you informed while we work on a fix.

## Security model

Azera is designed to be secure by default, but a few behaviours depend on how
you configure it:

- **SQL injection** — the `Query` builder and models emit prepared statements
  with bound parameters. Never interpolate user input into raw SQL fragments
  you pass to `where()`/`having()` helpers that accept raw SQL.
- **CSRF** — `CsrfMiddleware` and the CSRF helpers protect state-changing form
  submissions. Register the middleware on any route that accepts POST/PUT/DELETE.
- **Encryption** — the encryption helpers use Sodium when available and fall
  back to OpenSSL. Provide a strong, application-specific key; the helpers do
  not derive one for you.
- **Password hashing** — `Hasher` delegates to PHP's `password_hash()`. Do not
  lower the cost factors below PHP's defaults in production.
- **Cookie/session storage** — pluggable handlers are trusted code. Validate
  and bound anything you persist there yourself.

If you believe you can bypass one of these protections under a default
configuration, that is a vulnerability and we want to hear about it.
