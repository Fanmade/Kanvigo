<?php

use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->editor = userWithRole($this->project, 'member');
    $this->viewer = userWithRole($this->project, 'viewer');
});

describe('listing docs', function () {
    it('lists a project docs in tree order', function () {
        Sanctum::actingAs($this->editor, ['read']);

        $parent = Doc::factory()->for($this->project)->published()->create(['title' => 'Architecture']);
        $child = Doc::factory()->childOf($parent)->create(['title' => 'Storage']);

        $this->getJson('/api/v1/projects/ABC/docs')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.reference', $parent->reference)
            ->assertJsonPath('data.0.parent', null)
            ->assertJsonPath('data.1.reference', $child->reference)
            ->assertJsonPath('data.1.parent', $parent->reference)
            ->assertJsonPath('data.1.is_public', false);
    });

    it('omits drafts for a member who cannot edit docs', function () {
        Sanctum::actingAs($this->viewer, ['read']);

        $published = Doc::factory()->for($this->project)->published()->create();
        Doc::factory()->for($this->project)->create();

        $this->getJson('/api/v1/projects/ABC/docs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $published->reference);
    });

    it('filters to the docs nested under a parent', function () {
        Sanctum::actingAs($this->editor, ['read']);

        $parent = Doc::factory()->for($this->project)->create();
        $child = Doc::factory()->childOf($parent)->create();
        Doc::factory()->for($this->project)->create();

        $this->getJson("/api/v1/projects/ABC/docs?parent={$parent->reference}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $child->reference);
    });

    it('404s listing docs of a project the token owner cannot see', function () {
        Sanctum::actingAs($this->editor, ['read']);
        Project::factory()->create(['short_name' => 'XYZ']);

        $this->getJson('/api/v1/projects/XYZ/docs')->assertNotFound();
    });
});

describe('reading a doc', function () {
    it('returns a doc with its body, nesting, links and attachments', function () {
        Sanctum::actingAs($this->editor, ['read']);

        $doc = Doc::factory()->for($this->project)->published()->create([
            'title' => 'Style guide',
            'body' => '<p>Write plainly.</p>',
        ]);
        $child = Doc::factory()->childOf($doc)->published()->create();
        $task = Task::factory()->for($this->project)->create();
        $doc->addReference($task);
        $doc->syncTags(['design']);

        $this->getJson("/api/v1/docs/{$doc->reference}")
            ->assertOk()
            ->assertJsonPath('data.reference', $doc->reference)
            ->assertJsonPath('data.project', 'ABC')
            ->assertJsonPath('data.body', '<p>Write plainly.</p>')
            ->assertJsonPath('data.tags', ['design'])
            ->assertJsonPath('data.children.0.reference', $child->reference)
            ->assertJsonPath('data.references', [$task->reference])
            ->assertJsonPath('data.attachments', []);
    });

    it('reports the backlinks written inline in another item', function () {
        Sanctum::actingAs($this->editor, ['read']);

        $doc = Doc::factory()->for($this->project)->published()->create();
        $task = Task::factory()->for($this->project)->create([
            'description' => '<p>'.inlineReference($doc).'</p>',
        ]);

        $this->getJson("/api/v1/docs/{$doc->reference}")
            ->assertOk()
            ->assertJsonPath('data.referenced_by', [$task->reference]);
    });

    it('404s on a draft for a member who cannot edit docs', function () {
        Sanctum::actingAs($this->viewer, ['read']);
        $draft = Doc::factory()->for($this->project)->create();

        $this->getJson("/api/v1/docs/{$draft->reference}")->assertNotFound();
    });

    it('404s on an unknown or malformed reference', function () {
        Sanctum::actingAs($this->editor, ['read']);

        $this->getJson('/api/v1/docs/ABC-D99')->assertNotFound();
        $this->getJson('/api/v1/docs/nonsense')->assertNotFound();
    });
});

describe('writing docs', function () {
    it('creates a draft doc', function () {
        Sanctum::actingAs($this->editor, ['read', 'write']);

        $this->postJson('/api/v1/projects/ABC/docs', [
            'title' => 'Style guide',
            'body' => '<p>Write plainly.</p>',
        ])
            ->assertCreated()
            ->assertJsonPath('data.reference', 'ABC-D1')
            ->assertJsonPath('data.is_public', false)
            ->assertJsonPath('data.parent', null);

        expect(Doc::firstOrFail()->title)->toBe('Style guide');
    });

    it('creates a published doc nested under a parent', function () {
        Sanctum::actingAs($this->editor, ['read', 'write']);
        $parent = Doc::factory()->for($this->project)->create();

        $this->postJson('/api/v1/projects/ABC/docs', [
            'title' => 'Storage',
            'parent' => $parent->reference,
            'is_public' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.parent', $parent->reference)
            ->assertJsonPath('data.is_public', true);
    });

    it('404s creating a doc under a parent from another project', function () {
        Sanctum::actingAs($this->editor, ['read', 'write']);
        $other = Project::factory()->create(['short_name' => 'XYZ']);
        joinProject($other, $this->editor);
        $foreign = Doc::factory()->for($other)->create();

        $this->postJson('/api/v1/projects/ABC/docs', ['title' => 'Storage', 'parent' => $foreign->reference])
            ->assertNotFound();
    });

    it('403s creating a doc without the create-doc permission', function () {
        Sanctum::actingAs($this->viewer, ['read', 'write']);

        $this->postJson('/api/v1/projects/ABC/docs', ['title' => 'Style guide'])->assertForbidden();

        expect(Doc::count())->toBe(0);
    });

    it('403s creating a doc with a read-only token', function () {
        Sanctum::actingAs($this->editor, ['read']);

        $this->postJson('/api/v1/projects/ABC/docs', ['title' => 'Style guide'])->assertForbidden();
    });

    it('422s creating a doc without a title', function () {
        Sanctum::actingAs($this->editor, ['read', 'write']);

        $this->postJson('/api/v1/projects/ABC/docs', [])->assertStatus(422)->assertJsonValidationErrors('title');
    });

    it('patches only the fields sent', function () {
        Sanctum::actingAs($this->editor, ['read', 'write']);
        $doc = Doc::factory()->for($this->project)->published()->create(['title' => 'Old', 'body' => '<p>Body</p>']);

        $this->patchJson("/api/v1/docs/{$doc->reference}", ['title' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.title', 'New')
            ->assertJsonPath('data.is_public', true);

        expect($doc->refresh()->body)->toContain('Body');
    });

    it('re-parents a doc and moves it back to the top level', function () {
        Sanctum::actingAs($this->editor, ['read', 'write']);
        $parent = Doc::factory()->for($this->project)->create();
        $doc = Doc::factory()->for($this->project)->create();

        $this->patchJson("/api/v1/docs/{$doc->reference}", ['parent' => $parent->reference])
            ->assertOk()
            ->assertJsonPath('data.parent', $parent->reference);

        $this->patchJson("/api/v1/docs/{$doc->reference}", ['parent' => null])
            ->assertOk()
            ->assertJsonPath('data.parent', null);

        expect($doc->refresh()->parent_id)->toBeNull();
    });

    it('422s nesting a doc under its own nested doc', function () {
        Sanctum::actingAs($this->editor, ['read', 'write']);
        $doc = Doc::factory()->for($this->project)->create();
        $child = Doc::factory()->childOf($doc)->create();

        $this->patchJson("/api/v1/docs/{$doc->reference}", ['parent' => $child->reference])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent');

        expect($doc->refresh()->parent_id)->toBeNull();
    });

    it('403s updating a doc without the edit-doc permission', function () {
        Sanctum::actingAs($this->viewer, ['read', 'write']);
        $doc = Doc::factory()->for($this->project)->published()->create(['title' => 'Style guide']);

        $this->patchJson("/api/v1/docs/{$doc->reference}", ['title' => 'Hijacked'])->assertForbidden();

        expect($doc->refresh()->title)->toBe('Style guide');
    });

    it('deletes a doc, surfacing the docs nested under it at the top level', function () {
        Sanctum::actingAs($this->editor, ['read', 'write']);
        $doc = Doc::factory()->for($this->project)->create();
        $child = Doc::factory()->childOf($doc)->create(['title' => 'Storage']);

        $this->deleteJson("/api/v1/docs/{$doc->reference}")->assertNoContent();

        $this->getJson("/api/v1/docs/{$doc->reference}")->assertNotFound();

        // The nested doc survives the (soft) delete and now reads as top-level,
        // so nothing is stranded behind a doc that is no longer there.
        $this->getJson("/api/v1/docs/{$child->reference}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Storage')
            ->assertJsonPath('data.parent', null);
    });

    it('403s deleting a doc without the delete-doc permission', function () {
        Sanctum::actingAs($this->viewer, ['read', 'write']);
        $doc = Doc::factory()->for($this->project)->published()->create();

        $this->deleteJson("/api/v1/docs/{$doc->reference}")->assertForbidden();

        expect(Doc::find($doc->id))->not->toBeNull();
    });
});
