<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The transactional audit outbox: Audit::record() INSERTs every event here
     * inside whatever transaction is active, so an aborted action leaves no
     * orphan audit. The monotonic id gives queued sinks a total order; the
     * idempotency key makes at-least-once drain retries safe. Rows with a
     * dispatched_at are done (nothing left to drain) and get pruned.
     */
    public function up(): void
    {
        Schema::create('audit_outbox', static function (Blueprint $table): void {
            $table->id();
            $table->uuid('idempotency_key')->unique();
            $table->json('event');
            $table->timestamp('created_at');
            $table->timestamp('dispatched_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_outbox');
    }
};
