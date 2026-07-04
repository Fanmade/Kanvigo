<?php

namespace Tests\Support\Audit;

use App\Support\Facades\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditEvent;

/**
 * Emits an audit event from inside a queue worker, so tests can assert the
 * context resolver attributes worker-emitted events to the "queue" source.
 */
class EmitAuditEventJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Audit::record(AuditEvent::make('queue_probe', AuditCategory::Security));
    }
}
