<?php

use App\Livewire\Docs\DocList;
use App\Models\Doc;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->editor = userWithRole($this->project, 'member');
    $this->viewer = userWithRole($this->project, 'viewer');
});

describe('doc index', function () {
    it('renders the project docs as a tree for an editor', function () {
        $parent = Doc::factory()->for($this->project)->create(['title' => 'Architecture']);
        $child = Doc::factory()->childOf($parent)->create(['title' => 'Storage']);

        Livewire::actingAs($this->editor)
            ->test(DocList::class, ['short_name' => 'ABC'])
            ->assertSeeHtml('data-test="doc-row-'.$parent->id.'"')
            ->assertSeeHtml('data-test="doc-row-'.$child->id.'"')
            ->assertSeeHtml('data-test="new-doc"');
    });

    it('hides drafts from a viewer and offers them no create button', function () {
        $draft = Doc::factory()->for($this->project)->create();
        $published = Doc::factory()->for($this->project)->published()->create();

        Livewire::actingAs($this->viewer)
            ->test(DocList::class, ['short_name' => 'ABC'])
            ->assertSeeHtml('data-test="doc-row-'.$published->id.'"')
            ->assertDontSeeHtml('data-test="doc-row-'.$draft->id.'"')
            ->assertDontSeeHtml('data-test="new-doc"');
    });

    it('still lists a published doc whose parent is a hidden draft', function () {
        $draftParent = Doc::factory()->for($this->project)->create();
        $published = Doc::factory()->childOf($draftParent)->published()->create();

        Livewire::actingAs($this->viewer)
            ->test(DocList::class, ['short_name' => 'ABC'])
            ->assertSeeHtml('data-test="doc-row-'.$published->id.'"')
            ->assertDontSeeHtml('data-test="doc-row-'.$draftParent->id.'"');
    });

    it('narrows the list to matching docs when searching', function () {
        $match = Doc::factory()->for($this->project)->create(['title' => 'Release checklist']);
        $other = Doc::factory()->for($this->project)->create(['title' => 'Glossary']);

        Livewire::actingAs($this->editor)
            ->test(DocList::class, ['short_name' => 'ABC'])
            ->set('search', 'checklist')
            ->assertSeeHtml('data-test="doc-row-'.$match->id.'"')
            ->assertDontSeeHtml('data-test="doc-row-'.$other->id.'"');
    });

    it('denies a non-member', function () {
        $stranger = User::factory()->create();

        Livewire::actingAs($stranger)
            ->test(DocList::class, ['short_name' => 'ABC'])
            ->assertForbidden();
    });

    it('is reachable at /{SHORT}/docs', function () {
        $this->actingAs($this->editor)->get('/ABC/docs')->assertOk();
    });
});

describe('creating a doc', function () {
    it('creates a draft doc and opens it', function () {
        Livewire::actingAs($this->editor)
            ->test(DocList::class, ['short_name' => 'ABC'])
            ->call('startCreating')
            ->assertSet('creating', true)
            ->set('newTitle', 'Style guide')
            ->call('create')
            ->assertRedirect('/ABC-D1');

        $doc = Doc::firstOrFail();

        expect($doc->title)->toBe('Style guide')
            ->and($doc->is_public)->toBeFalse()
            ->and($doc->parent_id)->toBeNull()
            ->and($doc->project_id)->toBe($this->project->id);
    });

    it('nests the new doc under the chosen parent', function () {
        $parent = Doc::factory()->for($this->project)->create();

        Livewire::actingAs($this->editor)
            ->test(DocList::class, ['short_name' => 'ABC'])
            ->call('startCreating', $parent->id)
            ->assertSet('newParentId', $parent->id)
            ->set('newTitle', 'Storage')
            ->call('create');

        expect(Doc::where('title', 'Storage')->firstOrFail()->parent_id)->toBe($parent->id);
    });

    it('requires a title', function () {
        Livewire::actingAs($this->editor)
            ->test(DocList::class, ['short_name' => 'ABC'])
            ->set('newTitle', '')
            ->call('create')
            ->assertHasErrors(['newTitle' => 'required']);

        expect(Doc::count())->toBe(0);
    });

    it('rejects a parent from another project', function () {
        $foreign = Doc::factory()->create();

        Livewire::actingAs($this->editor)
            ->test(DocList::class, ['short_name' => 'ABC'])
            ->set('newTitle', 'Storage')
            ->set('newParentId', $foreign->id)
            ->call('create')
            ->assertHasErrors('newParentId');
    });

    it('does not let a viewer create a doc', function () {
        Livewire::actingAs($this->viewer)
            ->test(DocList::class, ['short_name' => 'ABC'])
            ->set('newTitle', 'Style guide')
            ->call('create')
            ->assertForbidden();

        expect(Doc::count())->toBe(0);
    });
});
