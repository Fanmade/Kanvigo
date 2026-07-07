<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\RichTextSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RichTextSanitizer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        $this->configureDefaults();
        $this->registerPermissionGate();
        $this->invalidateUnreadNotificationCountOnChange();
    }

    /**
     * Route gate checks through the delegated-permissions resolver, with the
     * API token project scope enforced first. This replaces the package's own
     * Gate::before (disabled via `delegated-permissions.register_gate`): the
     * package hook grants any ability matching a held permission before the
     * app could veto it, so a project-restricted token would keep authorizing
     * out-of-scope projects. Here the token check runs first and hard-denies
     * — skipping policies too — whenever the checked Project (or a Task's
     * project) falls outside the token's allowed set; everything else behaves
     * exactly like the package hook, via {@see User::hasScopedPermission()}.
     */
    protected function registerPermissionGate(): void
    {
        Gate::before(static function (mixed $user, string $ability, array $arguments = []): ?bool {
            if (! $user instanceof User) {
                return null;
            }

            $scope = ($arguments[0] ?? null) instanceof Model ? $arguments[0] : null;

            $project = match (true) {
                $scope instanceof Project => $scope,
                $scope instanceof Task => $scope->project,
                default => null,
            };

            if ($project !== null && ! $user->currentAccessTokenAllowsProject($project)) {
                return false;
            }

            return $user->hasScopedPermission($ability, $scope) ? true : null;
        });
    }

    /**
     * Bust a user's cached unread-notification count whenever their notifications
     * change — created (a new unread arrives), updated (marked read) or deleted —
     * so {@see User::unreadNotificationCount()} stays correct while remaining a
     * cache hit on the hot path.
     */
    protected function invalidateUnreadNotificationCountOnChange(): void
    {
        $forget = static function (DatabaseNotification $notification): void {
            User::forgetUnreadNotificationCount($notification->notifiable_id);
        };

        DatabaseNotification::created($forget);
        DatabaseNotification::updated($forget);
        DatabaseNotification::deleted($forget);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(static fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
