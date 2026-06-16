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
