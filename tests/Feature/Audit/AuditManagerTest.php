<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Facades\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Kanvigo\Audit\Contracts\Exceptions\AuditIntegrityException;
use Tests\Support\Audit\FailClosedSink;
use Tests\Support\Audit\FailingSink;
use Tests\Support\Audit\RecordingSink;
use Tests\Support\Audit\RejectingSink;
use Tests\Support\Audit\SecondRecordingSink;

uses(RefreshDatabase::class);

beforeEach(function () {
    RecordingSink::reset();
    SecondRecordingSink::reset();
    RejectingSink::reset();
    FailClosedSink::reset();

    $this->actor = User::factory()->create();
    $this->project = Project::factory()->withMembers([$this->actor])->create();
    $this->actingAs($this->actor);
});

/**
 * Swap the registered sinks for this test and re-resolve them.
 *
 * @param  list<class-string>  $sinks
 */
function useAuditSinks(array $sinks): void
{
    config()->set('audit.sinks', $sinks);
    Audit::flushSinks();
}

it('delivers an accepted event to every registered sink', function () {
    useAuditSinks([RecordingSink::class, SecondRecordingSink::class]);

    $task = Task::factory()->for($this->project)->create();

    expect(RecordingSink::$received)->toHaveCount(1)
        ->and(SecondRecordingSink::$received)->toHaveCount(1)
        ->and(RecordingSink::$received[0]->action)->toBe('created')
        ->and(RecordingSink::$received[0]->subjectType)->toBe(Task::class)
        ->and(RecordingSink::$received[0]->subjectId)->toBe($task->id)
        ->and(RecordingSink::$received[0]->actorId)->toBe($this->actor->id)
        ->and(RecordingSink::$received[0]->idempotencyKey)->not->toBeNull();
});

it('does not deliver an event to a sink that rejects it', function () {
    useAuditSinks([RejectingSink::class, RecordingSink::class]);

    Task::factory()->for($this->project)->create();

    expect(RejectingSink::$received)->toBeEmpty()
        ->and(RecordingSink::$received)->toHaveCount(1);
});

it('isolates a failing fail-open sink from the action and the other sinks', function () {
    Exceptions::fake();
    useAuditSinks([FailingSink::class, RecordingSink::class]);

    $task = Task::factory()->for($this->project)->create();

    expect($task->exists)->toBeTrue()
        ->and(RecordingSink::$received)->toHaveCount(1);

    Exceptions::assertReported(RuntimeException::class);
});

it('throws an integrity exception when a fail-closed sink accepts an event outside a transaction', function () {
    useAuditSinks([FailClosedSink::class]);

    expect(fn () => Task::factory()->for($this->project)->create())
        ->toThrow(AuditIntegrityException::class);
});

it('runs a fail-closed sink pre-commit and lets its failure abort the action', function () {
    useAuditSinks([FailClosedSink::class]);
    FailClosedSink::$failing = true;

    $outboxBaseline = DB::table('audit_outbox')->count();

    expect(fn () => DB::transaction(fn () => Task::factory()->for($this->project)->create()))
        ->toThrow(RuntimeException::class, 'The compliance ledger is unavailable.');

    expect(Task::query()->count())->toBe(0)
        ->and(DB::table('audit_outbox')->count())->toBe($outboxBaseline);
});

it('records through a fail-closed sink inside a transaction', function () {
    useAuditSinks([FailClosedSink::class]);

    $task = DB::transaction(fn () => Task::factory()->for($this->project)->create());

    expect($task->exists)->toBeTrue()
        ->and(FailClosedSink::$received)->toHaveCount(1)
        ->and(FailClosedSink::$received[0]->action)->toBe('created');
});

it('rejects a configured sink class that does not implement the contract', function () {
    useAuditSinks([stdClass::class]);

    expect(fn () => Task::factory()->for($this->project)->create())
        ->toThrow(InvalidArgumentException::class);
});
