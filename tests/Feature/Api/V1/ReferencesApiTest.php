<?php

use App\Enums\ReferenceOrigin;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Reference;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->editor = userWithRole($this->project, 'member');
    $this->viewer = userWithRole($this->project, 'viewer');
    $this->task = Task::factory()->for($this->project)->create();
    $this->doc = Doc::factory()->for($this->project)->published()->create();
});

describe('linking', function () {
    it('links a task to a doc', function () {
        Sanctum::actingAs($this->editor, ['read', 'write']);

        $this->postJson("/api/v1/tasks/{$this->task->reference}/references", ['related' => $this->doc->reference])
            ->assertCreated()
            ->assertJsonPath('data.reference', $this->task->reference)
            ->assertJsonPath('data.references', [$this->doc->reference]);

        expect(Reference::firstOrFail()->origin)->toBe(ReferenceOrigin::Manual);
    });

    it('links a doc to a task and gives the task a backlink', function () {
        Sanctum::actingAs($this->editor, ['read', 'write']);

        $this->postJson("/api/v1/docs/{$this->doc->reference}/references", ['related' => $this->task->reference])
            ->assertCreated()
            ->assertJsonPath('data.reference', $this->doc->reference)
            ->assertJsonPath('data.references', [$this->task->reference]);

        Sanctum::actingAs($this->editor, ['read']);

        $this->getJson("/api/v1/tasks/{$this->task->reference}")
            ->assertOk()
            ->assertJsonPath('data.referenced_by', [$this->doc->reference]);
    });

    it('422s linking an item to itself', function () {
        Sanctum::actingAs($this->editor, ['read', 'write']);

        $this->postJson("/api/v1/tasks/{$this->task->reference}/references", ['related' => $this->task->reference])
            ->assertStatus(422)
            ->assertJsonValidationErrors('related');
    });

    it('404s linking to an item the caller cannot see', function () {
        Sanctum::actingAs($this->editor, ['read', 'write']);
        $hidden = Task::factory()->create();

        $this->postJson("/api/v1/tasks/{$this->task->reference}/references", ['related' => $hidden->reference])
            ->assertNotFound();

        expect(Reference::count())->toBe(0);
    });

    it('404s linking from an item the caller cannot change', function () {
        Sanctum::actingAs($this->viewer, ['read', 'write']);

        $this->postJson("/api/v1/docs/{$this->doc->reference}/references", ['related' => $this->task->reference])
            ->assertNotFound();

        expect(Reference::count())->toBe(0);
    });

    it('403s linking with a read-only token', function () {
        Sanctum::actingAs($this->editor, ['read']);

        $this->postJson("/api/v1/tasks/{$this->task->reference}/references", ['related' => $this->doc->reference])
            ->assertForbidden();
    });
});

describe('unlinking', function () {
    it('removes only the link in the given direction', function () {
        Sanctum::actingAs($this->editor, ['read', 'write']);

        $this->task->addReference($this->doc);
        $this->doc->addReference($this->task);

        $this->deleteJson("/api/v1/tasks/{$this->task->reference}/references/{$this->doc->reference}")
            ->assertOk()
            ->assertJsonPath('data.references', []);

        expect($this->doc->references()->first()?->is($this->task))->toBeTrue();
    });

    it('404s unlinking two items that are not linked', function () {
        Sanctum::actingAs($this->editor, ['read', 'write']);

        $this->deleteJson("/api/v1/tasks/{$this->task->reference}/references/{$this->doc->reference}")
            ->assertNotFound();
    });
});

describe('reading references', function () {
    it('lists a task\'s links and backlinks, hiding drafts from a viewer', function () {
        $draft = Doc::factory()->for($this->project)->create();
        $this->task->addReference($this->doc);
        $this->task->addReference($draft);

        Sanctum::actingAs($this->viewer, ['read']);

        $this->getJson("/api/v1/tasks/{$this->task->reference}")
            ->assertOk()
            ->assertJsonPath('data.references', [$this->doc->reference]);
    });
});
