<?php

use App\Actions\CreateTask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

uses(RefreshDatabase::class);

/**
 * A Monolog handler that fails on every write, simulating an unwritable log
 * file (e.g. a laravel.log created by a cron job running as another user).
 */
class ExplodingLogHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        throw new RuntimeException('The stream or file could not be opened.');
    }
}

/**
 * Regression: a broken log sink must never amplify a handled tool exception
 * into a raw HTTP 500. Laravel MCP report()s tool exceptions before answering
 * with a graceful JSON-RPC error — if logging throws, that error path dies and
 * the client sees a 500 with no trace anywhere (KAN-413). The stack channel's
 * ignore_exceptions therefore swallows sink failures.
 */
it('degrades to a JSON-RPC error, not a 500, when a tool throws while logging is broken', function () {
    config([
        'app.debug' => false,
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['exploding'],
        'logging.channels.exploding' => [
            'driver' => 'monolog',
            'handler' => ExplodingLogHandler::class,
        ],
    ]);

    $user = User::factory()->create();
    Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);

    // Make the tool body throw after validation, like any unexpected bug would.
    $this->mock(CreateTask::class)->shouldReceive('handle')->andThrow(new RuntimeException('boom'));

    $token = $user->createToken('t', ['read', 'write'])->plainTextToken;

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'create-task-tool', 'arguments' => ['reference' => 'ABC', 'title' => 'x']],
    ]);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeTrue();
});
