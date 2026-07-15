<?php

use App\Audit\Pii\AuditRedactor;
use App\Enums\CancelReason;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Facades\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditContext;
use Kanvigo\Audit\Contracts\AuditEvent;
use Kanvigo\Audit\Contracts\AuditSource;
use Tests\Support\Audit\RecordingSink;
use Tests\Support\Audit\SecondRecordingSink;

uses(RefreshDatabase::class);

beforeEach(function () {
    RecordingSink::reset();
    SecondRecordingSink::reset();

    $this->actor = User::factory()->create();
    $this->project = Project::factory()->withMembers([$this->actor])->create();
    $this->actingAs($this->actor);
});

/**
 * Register the recording sink as an outbound (redacting) sink, optionally
 * alongside a second sink that stays internal.
 *
 * @param  array<class-string, array{redact?: bool}|string>  $sinks
 */
function useRedactingSinks(array $sinks = [RecordingSink::class => ['redact' => true]]): void
{
    config()->set('audit.sinks', $sinks);
    Audit::flushSinks();
}

/**
 * An event carrying one of every classification, with an explicit context so
 * the assertions don't depend on the test request.
 */
function eventWithPii(string $action = 'member_added', array $metadata = []): AuditEvent
{
    return AuditEvent::make($action, AuditCategory::Authz)
        ->withMetadata($metadata === [] ? ['member_id' => 7, 'member' => 'Ada Lovelace', 'role' => 'Editor'] : $metadata)
        ->withContext(new AuditContext(
            source: AuditSource::Api,
            ip: '203.0.113.9',
            userAgent: 'Mozilla/5.0',
            tokenName: 'Ada laptop',
        ));
}

it('tokenizes personal fields and drops sensitive ones before they reach an outbound sink', function () {
    useRedactingSinks();

    Audit::record(eventWithPii());

    $event = RecordingSink::$received[0];
    $token = app(AuditRedactor::class);

    expect($event->actorId)->toBe($token->tokenize($this->actor->id))
        ->and($event->context->ip)->toBe($token->tokenize('203.0.113.9'))
        ->and($event->metadata['member_id'])->toBe($token->tokenize(7))
        ->and($event->metadata['member'])->toBe($token->tokenize('Ada Lovelace'));

    expect($event->context->userAgent)->toBeNull()
        ->and($event->context->tokenName)->toBeNull()
        ->and($event->metadata)->not->toHaveKey('token');
});

it('leaves unclassified fields, the source and the event identity untouched', function () {
    useRedactingSinks();

    Audit::record(eventWithPii());

    $event = RecordingSink::$received[0];

    expect($event->metadata['role'])->toBe('Editor')
        ->and($event->context->source)->toBe(AuditSource::Api)
        ->and($event->action)->toBe('member_added')
        ->and($event->category)->toBe(AuditCategory::Authz)
        ->and($event->idempotencyKey)->not->toBeNull()
        ->and($event->occurredAt)->not->toBeNull();
});

it('keeps the internal record at full fidelity while the outbound copy is redacted', function () {
    useRedactingSinks([
        RecordingSink::class => ['redact' => true],
        SecondRecordingSink::class,
    ]);

    Audit::record(eventWithPii());

    // The internal sink sees the real values...
    $internal = SecondRecordingSink::$received[0];
    expect($internal->actorId)->toBe($this->actor->id)
        ->and($internal->context->ip)->toBe('203.0.113.9')
        ->and($internal->metadata['member'])->toBe('Ada Lovelace');

    // ...as does the replayable outbox row.
    $stored = json_decode(DB::table('audit_outbox')->orderByDesc('id')->value('event'), true);
    expect($stored['actor_id'])->toBe($this->actor->id)
        ->and($stored['context']['user_agent'])->toBe('Mozilla/5.0')
        ->and($stored['metadata']['member'])->toBe('Ada Lovelace');

    // ...but the outbound copy does not.
    expect(RecordingSink::$received[0]->metadata['member'])->not->toBe('Ada Lovelace');
});

it('tokenizes the same person to the same token across events and fields', function () {
    useRedactingSinks();

    Audit::record(eventWithPii(metadata: ['member_id' => $this->actor->id]));
    Audit::record(eventWithPii(metadata: ['member_id' => $this->actor->id]));

    [$first, $second] = RecordingSink::$received;

    expect($first->metadata['member_id'])->toBe($second->metadata['member_id'])
        ->and($first->metadata['member_id'])->toBe($first->actorId);

    expect(app(AuditRedactor::class)->tokenize('someone else'))->not->toBe($first->actorId);
});

it('classifies the free-text payload of a cancellation as sensitive without touching ordinary changes', function () {
    useRedactingSinks();

    Audit::record(eventWithPii('canceled', ['field' => 'cancellation', 'new' => '{"reason":"WontFix","message":"Ada asked to drop it"}']));
    Audit::record(eventWithPii('title_changed', ['field' => 'title', 'old' => 'Old title', 'new' => 'New title']));

    [$canceled, $renamed] = RecordingSink::$received;

    expect($canceled->metadata)->not->toHaveKey('new')
        ->and($canceled->metadata['field'])->toBe('cancellation');

    expect($renamed->metadata['old'])->toBe('Old title')
        ->and($renamed->metadata['new'])->toBe('New title');
});

it('redacts events emitted by real actions', function () {
    useRedactingSinks();

    $task = Task::factory()->for($this->project)->create();
    $task->cancel(CancelReason::WontFix, 'Ada asked to drop it');

    $canceled = collect(RecordingSink::$received)->firstWhere('action', 'canceled');

    expect($canceled)->not->toBeNull()
        ->and($canceled->metadata)->not->toHaveKey('new')
        ->and($canceled->actorId)->toBe(app(AuditRedactor::class)->tokenize($this->actor->id));
});
