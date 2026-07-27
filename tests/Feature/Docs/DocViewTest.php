<?php

use App\Livewire\Docs\DocView;
use App\Livewire\Tasks\TaskView;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->editor = userWithRole($this->project, 'member');
    $this->viewer = userWithRole($this->project, 'viewer');
});

describe('reading a doc', function () {
    it('renders the doc body for a member', function () {
        $doc = Doc::factory()->for($this->project)->create([
            'title' => 'Style guide',
            'body' => '<p>Write plainly.</p>',
        ]);

        Livewire::actingAs($this->editor)
            ->test(DocView::class, ['short_name' => 'ABC', 'doc_number' => $doc->doc_number])
            ->assertSee('Style guide')
            ->assertSeeHtml('Write plainly.')
            ->assertSeeHtml('data-test="edit-doc"');
    });

    it('is reachable at /{SHORT}-D{n} without colliding with the task route', function () {
        $doc = Doc::factory()->for($this->project)->create();
        $task = Task::factory()->for($this->project)->create();

        $this->actingAs($this->editor)->get('/ABC-D'.$doc->doc_number)->assertOk()->assertSee($doc->title);
        $this->actingAs($this->editor)->get('/ABC-'.$task->task_number)->assertOk()->assertSee($task->title);
    });

    it('hides a draft from a viewer but shows a published doc read-only', function () {
        $draft = Doc::factory()->for($this->project)->create();
        $published = Doc::factory()->for($this->project)->published()->create();

        Livewire::actingAs($this->viewer)
            ->test(DocView::class, ['short_name' => 'ABC', 'doc_number' => $draft->doc_number])
            ->assertForbidden();

        Livewire::actingAs($this->viewer)
            ->test(DocView::class, ['short_name' => 'ABC', 'doc_number' => $published->doc_number])
            ->assertOk()
            ->assertDontSeeHtml('data-test="edit-doc"');
    });

    it('lists the nested docs, hiding drafts from a viewer', function () {
        $parent = Doc::factory()->for($this->project)->published()->create();
        $publishedChild = Doc::factory()->childOf($parent)->published()->create();
        $draftChild = Doc::factory()->childOf($parent)->create();

        Livewire::actingAs($this->editor)
            ->test(DocView::class, ['short_name' => 'ABC', 'doc_number' => $parent->doc_number])
            ->assertSeeHtml('data-test="doc-row-'.$publishedChild->id.'"')
            ->assertSeeHtml('data-test="doc-row-'.$draftChild->id.'"');

        Livewire::actingAs($this->viewer)
            ->test(DocView::class, ['short_name' => 'ABC', 'doc_number' => $parent->doc_number])
            ->assertSeeHtml('data-test="doc-row-'.$publishedChild->id.'"')
            ->assertDontSeeHtml('data-test="doc-row-'.$draftChild->id.'"');
    });
});

describe('doc cross-references', function () {
    it('lists linked items and backlinks the viewer may see', function () {
        $doc = Doc::factory()->for($this->project)->published()->create();
        $task = Task::factory()->for($this->project)->create();
        $draft = Doc::factory()->for($this->project)->create();
        $backlinking = Task::factory()->for($this->project)->create();

        $doc->addReference($task);
        $doc->addReference($draft);
        $backlinking->addReference($doc);

        Livewire::actingAs($this->viewer)
            ->test(DocView::class, ['short_name' => 'ABC', 'doc_number' => $doc->doc_number])
            ->assertSeeHtml('data-test="reference-item-'.$task->reference.'"')
            ->assertSeeHtml('data-test="reference-item-'.$backlinking->reference.'"')
            ->assertDontSeeHtml('data-test="reference-item-'.$draft->reference.'"');
    });
});

describe('editing a doc', function () {
    it('updates the title, body and parent', function () {
        $parent = Doc::factory()->for($this->project)->create();
        $doc = Doc::factory()->for($this->project)->create(['title' => 'Old', 'body' => '<p>Old body</p>']);

        Livewire::actingAs($this->editor)
            ->test(DocView::class, ['short_name' => 'ABC', 'doc_number' => $doc->doc_number])
            ->call('edit')
            ->assertSet('title', 'Old')
            ->set('title', 'New')
            ->set('body', '<p>New body</p>')
            ->set('parentId', $parent->id)
            ->call('save')
            ->assertSet('editing', false)
            ->assertHasNoErrors();

        $doc->refresh();

        expect($doc->title)->toBe('New')
            ->and($doc->body)->toContain('New body')
            ->and($doc->parent_id)->toBe($parent->id);
    });

    it('surfaces a parent that would close a cycle as a field error', function () {
        $doc = Doc::factory()->for($this->project)->create();
        $child = Doc::factory()->childOf($doc)->create();

        Livewire::actingAs($this->editor)
            ->test(DocView::class, ['short_name' => 'ABC', 'doc_number' => $doc->doc_number])
            ->call('edit')
            ->set('parentId', $child->id)
            ->call('save')
            ->assertHasErrors('parentId');

        expect($doc->refresh()->parent_id)->toBeNull();
    });

    it('leaves out the doc itself and its descendants as parent options', function () {
        $doc = Doc::factory()->for($this->project)->create();
        $child = Doc::factory()->childOf($doc)->create();
        $grandchild = Doc::factory()->childOf($child)->create();
        $other = Doc::factory()->for($this->project)->create();

        $options = Livewire::actingAs($this->editor)
            ->test(DocView::class, ['short_name' => 'ABC', 'doc_number' => $doc->doc_number])
            ->instance()
            ->parentOptions();

        expect(array_keys($options))->toBe([$other->id])
            ->and(array_keys($options))->not->toContain($child->id, $grandchild->id);
    });

    it('publishes a draft and takes it back to draft', function () {
        $doc = Doc::factory()->for($this->project)->create();

        $component = Livewire::actingAs($this->editor)
            ->test(DocView::class, ['short_name' => 'ABC', 'doc_number' => $doc->doc_number])
            ->call('togglePublished');

        expect($doc->refresh()->is_public)->toBeTrue();

        $component->call('togglePublished');

        expect($doc->refresh()->is_public)->toBeFalse();
    });

    it('does not let a viewer edit or publish a doc', function () {
        $doc = Doc::factory()->for($this->project)->published()->create();

        Livewire::actingAs($this->viewer)
            ->test(DocView::class, ['short_name' => 'ABC', 'doc_number' => $doc->doc_number])
            ->call('edit')
            ->assertForbidden();

        Livewire::actingAs($this->viewer)
            ->test(DocView::class, ['short_name' => 'ABC', 'doc_number' => $doc->doc_number])
            ->call('togglePublished')
            ->assertForbidden();

        expect($doc->refresh()->is_public)->toBeTrue();
    });
});

describe('references on the task page', function () {
    it('shows what a task links to and what links back, hiding drafts', function () {
        $doc = Doc::factory()->for($this->project)->published()->create();
        $draft = Doc::factory()->for($this->project)->create();
        $task = Task::factory()->for($this->project)->create([
            'description' => '<p>'.inlineReference($doc).inlineReference($draft).'</p>',
        ]);

        $backlinking = Doc::factory()->for($this->project)->published()->create([
            'body' => '<p>'.inlineReference($task).'</p>',
        ]);

        Livewire::actingAs($this->viewer)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $task->task_number])
            ->assertSeeHtml('data-test="item-links"')
            ->assertSeeHtml('data-test="reference-item-'.$doc->reference.'"')
            ->assertSeeHtml('data-test="reference-item-'.$backlinking->reference.'"')
            ->assertDontSeeHtml('data-test="reference-item-'.$draft->reference.'"');
    });
});

describe('doc attachments', function () {
    it('uploads a file onto a doc, scoped to its project', function () {
        config()->set('attachments.disk', 'attachments');
        Storage::fake('attachments');

        $doc = Doc::factory()->for($this->project)->create();

        Livewire::actingAs($this->editor)
            ->test(DocView::class, ['short_name' => 'ABC', 'doc_number' => $doc->doc_number])
            ->set('newFiles', [UploadedFile::fake()->create('spec.pdf', 20, 'application/pdf')])
            ->assertHasNoErrors();

        $attachment = $doc->attachments()->firstOrFail();

        expect($attachment->name)->toBe('spec.pdf')
            ->and($attachment->ownerProject()?->id)->toBe($this->project->id);

        Storage::disk('attachments')->assertExists($attachment->path);
    });
});

describe('nesting and deleting', function () {
    it('creates a nested doc and opens it', function () {
        $doc = Doc::factory()->for($this->project)->create();

        Livewire::actingAs($this->editor)
            ->test(DocView::class, ['short_name' => 'ABC', 'doc_number' => $doc->doc_number])
            ->call('startCreatingChild')
            ->assertSet('creatingChild', true)
            ->set('childTitle', 'Storage')
            ->call('createChild')
            ->assertRedirect('/ABC-D2');

        expect(Doc::where('title', 'Storage')->firstOrFail()->parent_id)->toBe($doc->id);
    });

    it('deletes the doc and returns to the doc index', function () {
        $doc = Doc::factory()->for($this->project)->create();

        Livewire::actingAs($this->editor)
            ->test(DocView::class, ['short_name' => 'ABC', 'doc_number' => $doc->doc_number])
            ->call('delete')
            ->assertRedirect('/ABC/docs');

        expect(Doc::find($doc->id))->toBeNull();
    });

    it('does not let a viewer delete a doc', function () {
        $doc = Doc::factory()->for($this->project)->published()->create();

        Livewire::actingAs($this->viewer)
            ->test(DocView::class, ['short_name' => 'ABC', 'doc_number' => $doc->doc_number])
            ->call('delete')
            ->assertForbidden();

        expect(Doc::find($doc->id))->not->toBeNull();
    });

    it('denies a non-member', function () {
        $doc = Doc::factory()->for($this->project)->published()->create();
        $stranger = User::factory()->create();

        Livewire::actingAs($stranger)
            ->test(DocView::class, ['short_name' => 'ABC', 'doc_number' => $doc->doc_number])
            ->assertForbidden();
    });
});
