<?php

namespace App\Providers;

use App\Audit\AuditManager;
use App\Audit\ContextResolver;
use App\Audit\Listeners\RecordAuthenticationEvents;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AuditServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ContextResolver::class);
        $this->app->singleton(AuditManager::class);
    }

    /**
     * Track queue-job processing so the context resolver can attribute
     * events emitted inside a worker to the "queue" source (jobs carry no
     * request, so this flag is the only reliable signal).
     */
    public function boot(): void
    {
        Event::listen(JobProcessing::class, function (): void {
            $this->app->make(ContextResolver::class)->markQueueJob(true);
        });

        Event::listen(JobProcessed::class, function (): void {
            $this->app->make(ContextResolver::class)->markQueueJob(false);
        });

        Event::listen(JobExceptionOccurred::class, function (): void {
            $this->app->make(ContextResolver::class)->markQueueJob(false);
        });

        Event::subscribe(RecordAuthenticationEvents::class);
    }
}
