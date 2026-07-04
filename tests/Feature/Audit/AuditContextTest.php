<?php

use App\Audit\ContextResolver;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Kanvigo\Audit\Contracts\AuditSource;
use Tests\Support\Audit\EmitAuditEventJob;

use function Pest\Laravel\withHeaders;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->withMembers([$this->user])->create(['short_name' => 'ABC']);
});

/**
 * The context stamped onto the most recently emitted audit event.
 *
 * @return array<string, mixed>
 */
function latestAuditEvent(): array
{
    $row = DB::table('audit_outbox')->orderByDesc('id')->first();

    return json_decode((string) $row->event, true);
}

it('attributes a web-session action to the ui source with no token name', function () {
    $this->actingAs($this->user);

    Task::factory()->for($this->project)->create();

    $event = latestAuditEvent();

    expect($event['context']['source'])->toBe(AuditSource::Ui->value)
        ->and($event['context']['token_name'])->toBeNull()
        ->and($event['actor_id'])->toBe($this->user->id);
});

it('attributes an action through the MCP endpoint to the mcp source and token', function () {
    $token = $this->user->createToken('Claude', ['read', 'write'])->plainTextToken;

    withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'create-task-tool', 'arguments' => [
                'reference' => 'ABC',
                'title' => 'Created over MCP',
            ]],
        ])->assertOk();

    $event = latestAuditEvent();

    expect($event['action'])->toBe('created')
        ->and($event['context']['source'])->toBe(AuditSource::Mcp->value)
        ->and($event['context']['token_name'])->toBe('Claude')
        ->and($event['actor_id'])->toBe($this->user->id);
});

it('attributes an action through the REST API to the api source and token', function () {
    $task = Task::factory()->for($this->project)->create();
    $token = $this->user->createToken('CI deploy', ['read', 'write'])->plainTextToken;

    withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson("/api/v1/tasks/{$task->reference}/comments", ['body' => 'From the API'])
        ->assertCreated();

    $event = latestAuditEvent();

    expect($event['action'])->toBe('commented')
        ->and($event['context']['source'])->toBe(AuditSource::Api->value)
        ->and($event['context']['token_name'])->toBe('CI deploy');
});

it('attributes an event emitted inside a queue worker to the queue source', function () {
    EmitAuditEventJob::dispatch();

    $event = latestAuditEvent();

    expect($event['action'])->toBe('queue_probe')
        ->and($event['context']['source'])->toBe(AuditSource::Queue->value)
        // A worker-emitted event without an authenticated user has the system (null) actor.
        ->and($event['actor_id'])->toBeNull();
});

it('resolves the system source for console runs outside the test harness', function () {
    // runningUnitTests() keeps feature tests on the request path; masquerade as
    // a non-test console run so the console branch is reachable.
    $this->app->instance('env', 'production');

    try {
        $context = app(ContextResolver::class)->resolve();
    } finally {
        $this->app->instance('env', 'testing');
    }

    expect($context->source)->toBe(AuditSource::System)
        ->and($context->ip)->toBeNull()
        ->and($context->userAgent)->toBeNull();
});
