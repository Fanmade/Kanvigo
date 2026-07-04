<?php

use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Facades\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Audit\QueuedRecordingSink;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    QueuedRecordingSink::reset();

    $this->actor = User::factory()->create();
    $this->project = Project::factory()->withMembers([$this->actor])->create();
    $this->actingAs($this->actor);
});

it('inserts a serialized outbox row for every audited action', function () {
    $task = Task::factory()->for($this->project)->create();

    $row = DB::table('audit_outbox')->orderByDesc('id')->first();
    $event = json_decode((string) $row->event, true);

    expect($event['action'])->toBe('created')
        ->and($event['subject_type'])->toBe(Task::class)
        ->and($event['subject_id'])->toBe($task->id)
        ->and($event['actor_id'])->toBe($this->actor->id)
        ->and($row->idempotency_key)->not->toBeNull()
        // No queued sink is registered, so nothing awaits draining.
        ->and($row->dispatched_at)->not->toBeNull();
});

it('rolls the outbox row and the feed write back with an aborted action', function () {
    $outboxBaseline = DB::table('audit_outbox')->count();
    $activityBaseline = Activity::query()->count();

    expect(function () {
        DB::transaction(function (): void {
            Task::factory()->for($this->project)->create();

            throw new RuntimeException('The action failed after the audit write.');
        });
    })->toThrow(RuntimeException::class);

    expect(Task::query()->count())->toBe(0)
        ->and(DB::table('audit_outbox')->count())->toBe($outboxBaseline)
        ->and(Activity::query()->count())->toBe($activityBaseline);
});

it('leaves a row pending until the drain worker ships it to the queued sinks', function () {
    config()->set('audit.sinks', [QueuedRecordingSink::class]);
    Audit::flushSinks();

    Task::factory()->for($this->project)->create();

    $row = DB::table('audit_outbox')->orderByDesc('id')->first();

    expect($row->dispatched_at)->toBeNull()
        ->and(QueuedRecordingSink::$received)->toBeEmpty();

    artisan('audit:outbox:drain')->assertSuccessful();

    expect(QueuedRecordingSink::$received)->toHaveCount(1)
        ->and(QueuedRecordingSink::$received[0]->action)->toBe('created')
        ->and(QueuedRecordingSink::$received[0]->idempotencyKey)->toBe($row->idempotency_key)
        ->and(DB::table('audit_outbox')->whereNull('dispatched_at')->count())->toBe(0);
});

it('drains pending rows in outbox order', function () {
    config()->set('audit.sinks', [QueuedRecordingSink::class]);
    Audit::flushSinks();

    $first = Task::factory()->for($this->project)->create();
    $second = Task::factory()->for($this->project)->create();

    artisan('audit:outbox:drain')->assertSuccessful();

    expect(QueuedRecordingSink::$received)->toHaveCount(2)
        ->and(QueuedRecordingSink::$received[0]->subjectId)->toBe($first->id)
        ->and(QueuedRecordingSink::$received[1]->subjectId)->toBe($second->id);
});

it('keeps a failed row pending and redelivers it on the next drain', function () {
    config()->set('audit.sinks', [QueuedRecordingSink::class]);
    Audit::flushSinks();

    Task::factory()->for($this->project)->create();

    QueuedRecordingSink::$failing = true;
    artisan('audit:outbox:drain')->assertFailed();

    expect(QueuedRecordingSink::$received)->toBeEmpty()
        ->and(DB::table('audit_outbox')->whereNull('dispatched_at')->count())->toBe(1);

    QueuedRecordingSink::$failing = false;
    artisan('audit:outbox:drain')->assertSuccessful();

    expect(QueuedRecordingSink::$received)->toHaveCount(1)
        ->and(DB::table('audit_outbox')->whereNull('dispatched_at')->count())->toBe(0);
});

it('prunes only dispatched rows older than the retention window', function () {
    config()->set('audit.outbox.retention_days', 30);

    $insert = static fn (string $createdAt, ?string $dispatchedAt) => DB::table('audit_outbox')->insert([
        'idempotency_key' => (string) Str::uuid7(),
        'event' => json_encode(['v' => 1, 'action' => 'created', 'category' => 'content']),
        'created_at' => $createdAt,
        'dispatched_at' => $dispatchedAt,
    ]);

    $baseline = DB::table('audit_outbox')->count();

    $insert(now()->subDays(40)->toDateTimeString(), now()->subDays(40)->toDateTimeString());
    $insert(now()->subDays(5)->toDateTimeString(), now()->subDays(5)->toDateTimeString());
    $insert(now()->subDays(40)->toDateTimeString(), null);

    artisan('audit:outbox:prune')->assertSuccessful();

    // Only the old dispatched row is gone; recent and pending rows survive.
    expect(DB::table('audit_outbox')->count())->toBe($baseline + 2)
        ->and(DB::table('audit_outbox')->whereNull('dispatched_at')->count())->toBe(1);
});

it('keeps everything when retention is disabled', function () {
    config()->set('audit.outbox.retention_days', 0);

    Task::factory()->for($this->project)->create();
    DB::table('audit_outbox')->update(['created_at' => now()->subYears(2)->toDateTimeString()]);

    $count = DB::table('audit_outbox')->count();

    artisan('audit:outbox:prune')->assertSuccessful();

    expect(DB::table('audit_outbox')->count())->toBe($count);
});
