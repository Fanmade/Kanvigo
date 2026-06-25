<?php

namespace App\Providers;

use App\Models\User;
use App\Support\RichTextSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        $this->configureDefaults();
        $this->invalidateUnreadNotificationCountOnChange();
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
