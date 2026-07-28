# Testing & quality

## The quality gate

The full gate runs Pint, Larastan and Pest:

```bash
composer test
```

Individual checks:

```bash
composer lint          # Pint (apply fixes)
composer lint:check    # Pint (report only)
composer types:check   # Larastan / PHPStan
composer test:coverage # Pest with line coverage + minimum threshold
```

To run a subset of the fast suites, always scope to them explicitly:

```bash
php artisan test --testsuite=Unit,Feature --filter=SomeTest
```

CI measures line coverage on every run, fails if it drops below the configured
threshold, and publishes the current level to the coverage badge in the README.

## Browser tests

Browser tests (Pest 4 + Playwright) live in `tests/Browser` and run as a
separate suite, so the default gate stays fast and Playwright-free:

```bash
composer test:browser
```

They need the Playwright Chromium binary once:

```bash
npx playwright install chromium
```

Always run them through the composer script rather than
`php artisan test --testsuite=Browser`. The browser plugin leaks a
`playwright run-server` process on every run; the composer script reaps it
afterwards (preserving the exit code), while a bare artisan run leaves it
holding the command's output pipe open — which looks like a hang to anything
reading that pipe, and starves the machine as the leftovers pile up.

The same applies to `php artisan test` with only a `--filter`: with no
`--testsuite` it still boots the browser suite. Scope to `Unit,Feature`.

## Conventions

Project-specific testing rules live in `.ai/guidelines/`:

- `browser-tests.md` — selecting by `data-test`, asserting on selectors rather
  than visible text, screenshots.
- `performance-tests.md` — assert budgets that hold as data grows, never the
  query mechanism.
- `visual-only-changes.md` — when a presentational change needs no test.
