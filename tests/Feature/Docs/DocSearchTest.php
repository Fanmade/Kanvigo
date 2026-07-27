<?php

use App\Livewire\CommandPalette;
use App\Livewire\Docs\DocList;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\GlobalSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC', 'title' => 'Acme Board']);
    $this->editor = userWithRole($this->project, 'member');
    $this->viewer = userWithRole($this->project, 'viewer');
});

describe('finding docs', function () {
    it('finds a doc by its title', function () {
        Doc::factory()->for($this->project)->published()->create(['title' => 'Deployment runbook']);

        Livewire::actingAs($this->editor)
            ->test(CommandPalette::class)
            ->set('query', 'runbook')
            ->assertSee('Deployment runbook');
    });

    it('finds a doc by its tag', function () {
        $doc = Doc::factory()->for($this->project)->published()->create(['title' => 'Style guide']);
        $doc->syncTags(['design']);

        Livewire::actingAs($this->editor)
            ->test(CommandPalette::class)
            ->set('query', 'design')
            ->assertSee('Style guide');
    });

    it('marks a draft with a badge and hides it from members who cannot edit docs', function () {
        Doc::factory()->for($this->project)->create(['title' => 'Secret plan']);

        Livewire::actingAs($this->editor)
            ->test(CommandPalette::class)
            ->set('query', 'Secret')
            ->assertSee('Secret plan')
            ->assertSee('Draft');

        Livewire::actingAs($this->viewer)
            ->test(CommandPalette::class)
            ->set('query', 'Secret')
            ->assertDontSee('Secret plan');
    });

    it('omits docs of projects the user is not a member of', function () {
        $other = Project::factory()->create(['short_name' => 'XYZ']);
        Doc::factory()->for($other)->published()->create(['title' => 'Foreign notes']);

        Livewire::actingAs($this->editor)
            ->test(CommandPalette::class)
            ->set('query', 'Foreign')
            ->assertDontSee('Foreign notes');
    });

    it('jumps straight to a typed doc reference', function () {
        $doc = Doc::factory()->for($this->project)->published()->create(['title' => 'Style guide']);

        $results = app(GlobalSearch::class)->search($this->editor, $doc->reference);

        expect($results->first()?->type)->toBe('doc')
            ->and($results->first()?->pinned)->toBeTrue()
            ->and($results->first()?->reference)->toBe($doc->reference);

        // Lower case is fine; the compact "ABCD1" is not a doc reference — it
        // would just as well read as task 1 of a project "ABCD" — so it stays a
        // plain text search.
        expect(app(GlobalSearch::class)->search($this->editor, strtolower($doc->reference))->first()?->reference)
            ->toBe($doc->reference);
    });

    it('does not jump to a draft doc the user may not see', function () {
        $draft = Doc::factory()->for($this->project)->create();

        expect(app(GlobalSearch::class)->search($this->viewer, $draft->reference))->toBeEmpty();
    });

    it('still resolves a task reference alongside the doc form', function () {
        $task = Task::factory()->for($this->project)->create(['title' => 'Deploy fix']);

        $results = app(GlobalSearch::class)->search($this->editor, $task->reference);

        expect($results->first()?->type)->toBe('task')
            ->and($results->first()?->reference)->toBe($task->reference);
    });
});

describe('creating a doc from the palette', function () {
    it('offers a New doc action for the project being viewed', function () {
        Livewire::actingAs($this->editor)
            ->withUrlParams(['short_name' => 'ABC'])
            ->test(CommandPalette::class)
            ->assertSee('New doc');
    });

    it('offers it off a project page when the user has a single project', function () {
        Livewire::actingAs($this->editor)
            ->test(CommandPalette::class)
            ->assertSee('New doc');
    });

    it('leaves it out for a member who cannot create docs', function () {
        Livewire::actingAs($this->viewer)
            ->test(CommandPalette::class)
            ->assertDontSee('New doc');
    });

    it('leaves it out with several projects and no project context', function () {
        $second = Project::factory()->create(['short_name' => 'XYZ']);
        joinProject($second, $this->editor);

        Livewire::actingAs($this->editor)
            ->test(CommandPalette::class)
            ->assertDontSee('New doc');
    });

    it('opens the create dialog when the docs page is deep-linked with ?create=1', function () {
        Livewire::actingAs($this->editor)
            ->test(DocList::class, ['short_name' => 'ABC', 'creating' => true])
            ->assertSet('creating', true)
            ->assertSeeHtml('data-test="create-doc-modal"');
    });
});

describe('doc previews', function () {
    it('serves a compact preview for the reference hovercard', function () {
        $parent = Doc::factory()->for($this->project)->published()->create([
            'title' => 'Style guide',
            'body' => '<p>Write plainly and keep it short.</p>',
        ]);
        Doc::factory()->childOf($parent)->published()->create();

        $this->actingAs($this->editor)
            ->getJson("/ABC-D{$parent->doc_number}/preview")
            ->assertOk()
            ->assertJson([
                'type' => 'doc',
                'reference' => $parent->reference,
                'title' => 'Style guide',
                'visibility' => 'Published',
                'excerpt' => 'Write plainly and keep it short.',
            ])
            ->assertJsonPath('nested', '1 nested doc');
    });

    it('reports an empty draft without an excerpt', function () {
        $doc = Doc::factory()->for($this->project)->create(['body' => null]);

        $this->actingAs($this->editor)
            ->getJson("/ABC-D{$doc->doc_number}/preview")
            ->assertOk()
            ->assertJsonPath('visibility', 'Draft')
            ->assertJsonPath('excerpt', null)
            ->assertJsonPath('nested', null);
    });

    it('403s a draft preview for a member who cannot edit docs, and 404s an unknown doc', function () {
        $draft = Doc::factory()->for($this->project)->create();

        $this->actingAs($this->viewer)->getJson("/ABC-D{$draft->doc_number}/preview")->assertForbidden();
        $this->actingAs($this->editor)->getJson('/ABC-D999/preview')->assertNotFound();
    });

    it('is unreachable for a non-member', function () {
        $doc = Doc::factory()->for($this->project)->published()->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/ABC-D{$doc->doc_number}/preview")
            ->assertForbidden();
    });
});
