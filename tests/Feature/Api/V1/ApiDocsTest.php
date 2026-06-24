<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('serves the generated OpenAPI document for the v1 API', function () {
    // The docs are gated to local/authorized access; allow it for this request.
    Gate::define('viewApiDocs', fn (?User $user) => true);

    $doc = $this->actingAs(User::factory()->create())
        ->getJson('/docs/api.json')->assertOk()->json();

    expect($doc['info']['title'])->toBe('Kanvigo API')
        ->and(array_keys($doc['paths']))->toContain('/projects', '/tasks/{reference}')
        ->and($doc['paths']['/projects/{short_name}/tasks'])->toHaveKeys(['get', 'post'])
        ->and($doc['components']['securitySchemes'])->toHaveKey('http')
        ->and($doc['security'])->toBe([['http' => []]]);
});

it('restricts the API docs outside the local environment', function () {
    // The default RestrictedDocsAccess gate denies access in the testing environment.
    $this->get('/docs/api.json')->assertForbidden();
});
