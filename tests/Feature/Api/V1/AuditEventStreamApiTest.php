<?php

use App\Audit\Pii\AuditRedactor;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

/**
 * A token carrying the given abilities for the user, returned as its plain-text
 * Bearer value.
 *
 * @param  array<int, string>  $abilities
 */
function auditToken(User $user, array $abilities = ['audit']): string
{
    return $user->createToken('SIEM', $abilities)->plainTextToken;
}

beforeEach(function () {
    $this->operator = User::factory()->canManageUsers()->create();
    $this->project = Project::factory()->withMembers([$this->operator])->create();
    // A recorded action so the outbox is non-empty, attributed to the operator.
    $this->actingAs($this->operator);
    $this->task = Task::factory()->for($this->project)->create();

    // Drop the session guard so the token-authenticated requests below are
    // genuinely gated by the token's abilities, not the seeding session (whose
    // Sanctum fallback would satisfy tokenCan() unconditionally).
    Auth::forgetGuards();
});

it('rejects a read/write token without the audit ability', function () {
    withToken(auditToken($this->operator, ['read', 'write']))
        ->getJson('/api/v1/audit-events')
        ->assertForbidden();
});

it('rejects an audit token whose user lacks the manage-users permission', function () {
    $member = User::factory()->create();
    Project::factory()->withMembers([$member])->create();

    withToken(auditToken($member))
        ->getJson('/api/v1/audit-events')
        ->assertForbidden();
});

it('rejects an unauthenticated request', function () {
    $this->getJson('/api/v1/audit-events')->assertUnauthorized();
});

it('streams recorded events with the versioned schema and the outbox id as cursor', function () {
    $response = withToken(auditToken($this->operator))
        ->getJson('/api/v1/audit-events')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'v', 'action', 'category', 'subject_type', 'subject_id', 'actor_id', 'metadata', 'context']],
            'next_cursor',
        ]);

    expect($response->json('data.0.v'))->toBe(1)
        ->and($response->json('data.0.id'))->toBeInt();
});

it('applies the redaction boundary — the actor is tokenized, not the raw id', function () {
    $response = withToken(auditToken($this->operator))
        ->getJson('/api/v1/audit-events')
        ->assertOk();

    $created = collect($response->json('data'))
        ->first(fn (array $event): bool => $event['action'] === 'created'
            && $event['subject_type'] === $this->task->getMorphClass()
            && $event['subject_id'] === $this->task->id);

    expect($created)->not->toBeNull()
        ->and($created['actor_id'])->toBe(app(AuditRedactor::class)->tokenize($this->operator->id))
        ->and($created['actor_id'])->not->toBe($this->operator->id);
});

it('walks the outbox forward by cursor without repeating rows', function () {
    // Enough events that a small page leaves more behind.
    Task::factory()->count(4)->for($this->project)->create();

    $firstPage = withToken(auditToken($this->operator))
        ->getJson('/api/v1/audit-events?limit=2')
        ->assertOk();

    expect($firstPage->json('data'))->toHaveCount(2)
        ->and($firstPage->json('next_cursor'))->not->toBeNull();

    $after = $firstPage->json('next_cursor');

    $secondPage = withToken(auditToken($this->operator))
        ->getJson("/api/v1/audit-events?after={$after}&limit=2")
        ->assertOk();

    $firstIds = collect($firstPage->json('data'))->pluck('id');
    $secondIds = collect($secondPage->json('data'))->pluck('id');

    expect($secondIds->intersect($firstIds))->toBeEmpty()
        ->and($secondIds->min())->toBeGreaterThan($firstIds->max());
});

it('returns a null cursor once the consumer has caught up', function () {
    $lastId = DB::table('audit_outbox')->max('id');

    $response = withToken(auditToken($this->operator))
        ->getJson("/api/v1/audit-events?after={$lastId}")
        ->assertOk();

    expect($response->json('data'))->toBeEmpty()
        ->and($response->json('next_cursor'))->toBeNull();
});

it('rejects an out-of-range limit', function () {
    withToken(auditToken($this->operator))
        ->getJson('/api/v1/audit-events?limit=500')
        ->assertStatus(422);
});

it('records reading the stream but never reflects those reads back into it', function () {
    $token = auditToken($this->operator);

    // First read: records an `audit_stream_read` event in the outbox...
    withToken($token)->getJson('/api/v1/audit-events')->assertOk();

    expect(DB::table('audit_outbox')->count())->toBeGreaterThan(0);
    $streamReads = DB::table('audit_outbox')
        ->where('event->action', 'audit_stream_read')
        ->count();
    expect($streamReads)->toBe(1);

    // ...but a second read never returns the first read's own event, so a
    // polling consumer can't loop on itself.
    $second = withToken($token)->getJson('/api/v1/audit-events')->assertOk();

    expect(collect($second->json('data'))->pluck('action'))
        ->not->toContain('audit_stream_read');
});
