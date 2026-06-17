<laravel-boost-guidelines>
=== .ai/browser-tests rules ===

# Browser Tests

Conventions for Pest browser tests (`tests/Browser/`, the `visit()` API). These
prevent the most common avoidable failures.

## Select by data attribute, never by visible text

Target elements with a `data-test` attribute and Pest's `@` selector — **not** the
visible label. Pest resolves `@create-project` to `[data-testid=create-project],
[data-test=create-project]`.

```blade
<flux:button wire:click="$set('showCreate', true)" data-test="create-project">
    {{ __('New project') }}
</flux:button>
```

```php
$page->click('@create-project')
    ->fill('@project-title', 'My Cool Project')
    ->assertValue('@project-short-name', 'MCP');
```

Why: a visible label like "New project" is rarely unique — the same text can appear
in a page button, the command palette, the sidebar, or a heading. `click('New
project')` then resolves to the wrong (often hidden) element and times out instead
of failing clearly. Data attributes are unambiguous and survive copy edits and
translation. Add a `data-test` to any element a test interacts with or asserts on.

## `screenshot()` takes a boolean first, filename second

The signature is `screenshot(bool $fullPage = true, ?string $filename = null)`. The
**first** argument is `$fullPage`, not the filename — passing a filename first throws
a `TypeError`. Images are written to `tests/Browser/Screenshots/`.

```php
$page->screenshot();                         // full page, auto-named after the test
$page->screenshot(false, 'command-palette'); // viewport only, custom name
```

## Always assert no JS errors

End interactive browser tests with `->assertNoJavascriptErrors()` so silent
client-side failures surface.

=== .ai/feature-documentation rules ===

# Feature Documentation

Keep the documentation in sync with the application's features. Whenever you add
or modify a feature, update the docs in the same change — undocumented behavior
is treated as incomplete work.

## What to document

- The user-facing features list in `README.md`.
- Any feature-specific docs under `docs/`.

Describe **what** a feature does, not how it is implemented. Add a technical
detail only when it is relevant to using the feature (e.g. "registration is
invitation-only", "notifications auto-subscribe assignees"). Skip internal
mechanics, class names, and step-by-step implementation notes.

## Style

- Be concise and focused. Keep it basic — no trivial details.
- One feature, one short entry. Prefer a sentence over a paragraph.
- Match the tone and structure of the surrounding documentation.

## Boy Scout rule

Leave documentation cleaner than you found it. While editing any doc, fix issues
you notice in it — stale descriptions, broken references, removed features still
listed, inconsistent terminology — even if they are unrelated to your change.

=== .ai/static-closures rules ===

# Static Closures

Declare a closure `static` whenever its body does **not** use `$this`. This silences the IDE "closure can be declared static" hint and avoids needless `$this` binding. The rule applies to **both** arrow functions (`fn`) and multi-line closures (`function () { ... }`) — convert every form, not just short arrow functions.

```php
// Arrow functions
$ids->map(static fn (int $id): int => $id * 2);
Gate::define('create-projects', static fn (User $user): bool => $user->can_create_projects);

// Multi-line closures — same rule
Schema::create('users', static function (Blueprint $table): void { /* ... */ });
RateLimiter::for('login', static function (Request $request): Limit { /* ... */ });

// Eloquent model event hooks — static IS correct here. The model arrives as the
// closure argument, so the closure is never bound to an instance.
static::created(static function (Model $model): void {
    $model->recordActivity('created');
});
```

## Do NOT make these static — Laravel binds them to an instance at runtime

Marking them `static` throws `Cannot bind an instance to a static closure` (fatal in PHP 9). Leave them as regular closures even when no `$this` appears in the body:

- **Model factory closures**: `$this->state(...)`, `$this->afterMaking(...)`, `$this->afterCreating(...)`, and `configure()` callbacks. Eloquent rebinds these to the factory instance.
- **Attribute accessors/mutators** that read or write model state via `$this`: `Attribute::get(fn () => $this->...)`.
- **Any closure that uses `$this`**: Artisan command closures (`$this->info(...)`), route closures bound to a controller, `DB::transaction(fn () => $this->...)`, etc.

> Note: Eloquent model event hooks (`static::creating/created/updating/deleting/saved`, in `booted()` or a `bootHasX()` trait method) are **not** an exception — they take the model as an argument and should be `static`.

When unsure whether a closure gets bound, run the test suite — a static-closure binding error surfaces immediately.

## Pest tests

Ignore the "could be declared static" hint inside Pest test closures (`test()`, `it()`, `beforeEach()`, datasets, etc.). Pest binds those closures to the `TestCase`, so the hint is a false positive — do not make them static.

=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- livewire/flux (FLUXUI_FREE) - v2
- livewire/flux-pro (FLUXUI_PRO) - v2
- livewire/livewire (LIVEWIRE) - v4
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Follow existing application Enum naming conventions.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>
