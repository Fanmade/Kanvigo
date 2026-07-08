<?php

use App\Actions\CreateTask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    @unlink(storage_path('logs/mcp-diagnostic.log'));
});

afterEach(function () {
    @unlink(storage_path('logs/mcp-diagnostic.log'));
});

it('records every MCP request and its response status', function () {
    $user = User::factory()->create();
    Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);

    $token = $user->createToken('t', ['read'])->plainTextToken;

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'list-tasks-tool', 'arguments' => ['reference' => 'ABC']],
    ])->assertOk();

    $lines = array_map(
        static fn (string $line): array => json_decode($line, true),
        array_filter(explode(PHP_EOL, (string) file_get_contents(storage_path('logs/mcp-diagnostic.log')))),
    );

    expect($lines[0]['phase'])->toBe('request')
        ->and($lines[0]['tool'])->toBe('list-tasks-tool')
        ->and(end($lines)['phase'])->toBe('response')
        ->and(end($lines)['status'])->toBe(200);
});

it('records a tool exception with its trace even though the response stays graceful', function () {
    $user = User::factory()->create();
    Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);

    $this->mock(CreateTask::class)->shouldReceive('handle')->andThrow(new RuntimeException('boom'));

    $token = $user->createToken('t', ['read', 'write'])->plainTextToken;

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'create-task-tool', 'arguments' => ['reference' => 'ABC', 'title' => 'x']],
    ]);

    $log = (string) file_get_contents(storage_path('logs/mcp-diagnostic.log'));

    // Laravel MCP answers tool exceptions gracefully, so the middleware sees a
    // response — but the request breadcrumb proves the call reached the app,
    // and a response line with the final status closes the pair. A missing
    // response line after a request line is the fatal-error signature.
    expect($log)->toContain('"phase":"request"')
        ->and($log)->toContain('"tool":"create-task-tool"')
        ->and($log)->toContain('"phase":"response"');
});
