<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->user);
    $this->a = Task::factory()->for($this->project)->create();
    $this->b = Task::factory()->for($this->project)->create();
});

it('links a blocked_by dependency', function () {
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->postJson("/api/v1/tasks/{$this->a->reference}/dependencies", [
        'related' => $this->b->reference,
        'direction' => 'blocked_by',
    ])
        ->assertCreated()
        ->assertJsonPath('data.reference', $this->a->reference)
        ->assertJsonPath('data.blocked_by', [$this->b->reference]);

    expect($this->a->fresh()->blockers()->pluck('id'))->toContain($this->b->id);
});

it('links a blocks dependency', function () {
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->postJson("/api/v1/tasks/{$this->a->reference}/dependencies", [
        'related' => $this->b->reference,
        'direction' => 'blocks',
    ])
        ->assertCreated()
        ->assertJsonPath('data.blocks', [$this->b->reference]);
});

it('links a symmetric relates relationship without blocking', function () {
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->postJson("/api/v1/tasks/{$this->a->reference}/dependencies", [
        'related' => $this->b->reference,
        'direction' => 'relates',
    ])
        ->assertCreated()
        ->assertJsonPath('data.relates', [$this->b->reference])
        ->assertJsonPath('data.is_blocked', false);

    // It reads the same from the other end.
    Sanctum::actingAs($this->user, ['read']);
    $this->getJson("/api/v1/tasks/{$this->b->reference}")
        ->assertOk()
        ->assertJsonPath('data.relates', [$this->a->reference]);
});

it('links a directed duplicates relationship', function () {
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->postJson("/api/v1/tasks/{$this->a->reference}/dependencies", [
        'related' => $this->b->reference,
        'direction' => 'duplicates',
    ])
        ->assertCreated()
        ->assertJsonPath('data.duplicates', [$this->b->reference]);
});

it('rejects a cycle', function () {
    $this->a->addBlocker($this->b); // a is blocked by b
    Sanctum::actingAs($this->user, ['read', 'write']);

    // Making b blocked by a would close the loop.
    $this->postJson("/api/v1/tasks/{$this->b->reference}/dependencies", [
        'related' => $this->a->reference,
        'direction' => 'blocked_by',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('related');
});

it('forbids linking a dependency with a read-only token', function () {
    Sanctum::actingAs($this->user, ['read']);

    $this->postJson("/api/v1/tasks/{$this->a->reference}/dependencies", [
        'related' => $this->b->reference,
        'direction' => 'blocked_by',
    ])->assertForbidden();
});

it('unlinks a dependency in either direction', function () {
    $this->a->addBlocker($this->b);
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->deleteJson("/api/v1/tasks/{$this->a->reference}/dependencies/{$this->b->reference}")
        ->assertOk()
        ->assertJsonPath('data.blocked_by', []);

    expect($this->a->fresh()->blockers()->pluck('id'))->not->toContain($this->b->id);
});

it('404s unlinking a dependency that does not exist', function () {
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->deleteJson("/api/v1/tasks/{$this->a->reference}/dependencies/{$this->b->reference}")
        ->assertNotFound();
});

it('404s linking against a task in a project the user cannot access', function () {
    $other = Project::factory()->create(['short_name' => 'XYZ']);
    $foreign = Task::factory()->for($other)->create();
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->postJson("/api/v1/tasks/{$foreign->reference}/dependencies", [
        'related' => $this->a->reference,
        'direction' => 'blocked_by',
    ])->assertNotFound();
});
