<?php

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditContext;
use Kanvigo\Audit\Contracts\AuditEvent;
use Kanvigo\Audit\Contracts\AuditSource;

uses(RefreshDatabase::class);

/**
 * Queued audit sinks serialize the AuditEvent DTO, and the cache/queue
 * unserialize allow-list (config/cache.php serializable_classes) blocks any
 * class not explicitly listed. Like BoardCacheSerializationTest, this forces
 * the database store so a fully stamped event — enum-backed category/source,
 * a CarbonImmutable occurred-at — genuinely round-trips through serialize();
 * the default array store would keep the live object and never catch a
 * missing allow-list entry.
 */
it('round-trips a fully stamped audit event through the serialized cache store', function () {
    config()->set('cache.default', 'database');

    $event = AuditEvent::make('status_changed', AuditCategory::Content)
        ->withSubject(Task::class, 42)
        ->withActor(7)
        ->withMetadata(['field' => 'status', 'old' => 'todo', 'new' => 'done'])
        ->withTags('board')
        ->withContext(new AuditContext(AuditSource::Mcp, ip: '10.0.0.1', userAgent: 'Test/1.0', tokenName: 'Claude'))
        ->withOccurredAt(now()->toImmutable())
        ->withIdempotencyKey('0197fa10-0000-7000-8000-000000000000');

    Cache::put('audit-event-roundtrip', $event, 60);

    $restored = Cache::get('audit-event-roundtrip');

    expect($restored)->toBeInstanceOf(AuditEvent::class)
        ->and($restored->toArray())->toBe($event->toArray());
});
