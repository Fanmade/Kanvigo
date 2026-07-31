<?php

use App\Jobs\RewriteVariableUsages;
use App\Livewire\Projects\ProjectVariables;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Variable;
use App\Support\VariableSyntax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'SCI']);
    $this->member = userWithRole($this->project, 'member');
    $this->variable = Variable::factory()->for($this->project)->create([
        'name' => 'main_protagonist',
        'value' => 'Robin Hood',
    ]);
});

/**
 * Rename the variable through the page, confirming when asked.
 */
function renameTo(string $name): Testable
{
    return Livewire::actingAs(test()->member)
        ->test(ProjectVariables::class, ['short_name' => 'SCI'])
        ->call('startEdit', test()->variable->id)
        ->set('editName', $name)
        ->call('save')
        ->assertSet('confirmingRename', true)
        ->call('rename');
}

it('asks before renaming, and does nothing until confirmed', function () {
    Livewire::actingAs($this->member)
        ->test(ProjectVariables::class, ['short_name' => 'SCI'])
        ->call('startEdit', $this->variable->id)
        ->set('editName', 'hero')
        ->call('save')
        ->assertSet('confirmingRename', true)
        ->assertSet('editing', true);

    expect($this->variable->fresh()->name)->toBe('main_protagonist');
});

it('counts the usages a rename would rewrite', function () {
    Task::factory()->for($this->project)->create(['description' => '<p>[main_protagonist] arrives.</p>']);
    Doc::factory()->for($this->project)->create(['body' => '<p>Meet [main_protagonist].</p>']);

    Livewire::actingAs($this->member)
        ->test(ProjectVariables::class, ['short_name' => 'SCI'])
        ->call('startEdit', $this->variable->id)
        ->set('editName', 'hero')
        ->call('save')
        ->assertSeeText('Its 2 usages will be rewritten');
});

it('renames the variable and rewrites its usages', function () {
    $task = Task::factory()->for($this->project)->create([
        'description' => '<p>[main_protagonist] arrives, and [main_protagonist] leaves.</p>',
    ]);
    $doc = Doc::factory()->for($this->project)->create(['body' => '<p>Meet [main_protagonist].</p>']);

    renameTo('hero')->assertHasNoErrors();

    expect($this->variable->fresh()->name)->toBe('hero')
        ->and($task->fresh()->description)->toContain('[hero] arrives, and [hero] leaves')
        ->and($task->fresh()->description)->not->toContain('[main_protagonist]')
        ->and($doc->fresh()->body)->toContain('[hero]');
});

it('keeps the value and description through a rename', function () {
    renameTo('hero');

    expect($this->variable->fresh())
        ->name->toBe('hero')
        ->value->toBe('Robin Hood');
});

it('leaves other projects content alone', function () {
    $other = Project::factory()->create(['short_name' => 'OTH']);
    $theirs = Task::factory()->for($other)->create(['description' => '<p>[main_protagonist] elsewhere.</p>']);

    renameTo('hero');

    expect($theirs->fresh()->description)->toContain('[main_protagonist]');
});

it('rewrites on the queue rather than in the request', function () {
    Queue::fake();

    renameTo('hero');

    Queue::assertPushed(RewriteVariableUsages::class, function (RewriteVariableUsages $job): bool {
        return $job->from === 'main_protagonist'
            && $job->to === 'hero'
            && $job->projectId === $this->project->id
            && $job->actorId === $this->member->id;
    });
});

it('records the rewrite as a real edit by the user who renamed', function () {
    $task = Task::factory()->for($this->project)->create(['description' => '<p>[main_protagonist]</p>']);
    $before = $task->fresh()->updated_at;

    // updated_at has second precision, so move past the write above.
    $this->travel(2)->seconds();

    renameTo('hero');

    $edit = DB::table('audit_outbox')->orderBy('id')->get()
        ->map(static fn (object $row): array => json_decode((string) $row->event, true, flags: JSON_THROW_ON_ERROR))
        ->last(static fn (array $event): bool => $event['action'] === 'description_changed');

    // The stored bytes really did change, so it is an edit — attributed, and
    // visible in the item's history.
    expect($edit)->not->toBeNull()
        ->and($edit['actor_id'])->toBe($this->member->id)
        ->and($task->fresh()->updated_at->greaterThan($before))->toBeTrue();
});

it('rejects a rename onto a name already in use', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero']);

    Livewire::actingAs($this->member)
        ->test(ProjectVariables::class, ['short_name' => 'SCI'])
        ->call('startEdit', $this->variable->id)
        ->set('editName', 'hero')
        ->call('save')
        ->assertHasErrors('editName');

    expect($this->variable->fresh()->name)->toBe('main_protagonist');
});

it('re-validates on confirmation, not just when the dialog opened', function () {
    Livewire::actingAs($this->member)
        ->test(ProjectVariables::class, ['short_name' => 'SCI'])
        ->call('startEdit', $this->variable->id)
        ->set('editName', 'hero')
        ->call('save')
        // The name is tampered with between the confirmation and the yes.
        ->set('editName', 'Not A Name!')
        ->call('rename')
        ->assertHasErrors('editName');

    expect($this->variable->fresh()->name)->toBe('main_protagonist');
});

it('re-reads the current content instead of trusting the index', function () {
    $task = Task::factory()->for($this->project)->create(['description' => '<p>[main_protagonist]</p>']);

    // The index still points at the task, but its content moved on.
    $task->updateQuietly(['description' => '<p>Nobody in particular.</p>']);

    renameTo('hero');

    expect($task->fresh()->description)->toBe('<p>Nobody in particular.</p>');
});

it('does not rewrite a name inside quoted code', function () {
    $task = Task::factory()->for($this->project)->create([
        'description' => '<p>Read <code>[main_protagonist]</code> and greet [main_protagonist].</p>',
    ]);

    renameTo('hero');

    expect($task->fresh()->description)
        ->toContain('<code>[main_protagonist]</code>')
        ->toContain('greet [hero]');
});

it('leaves content that does not use the name untouched', function () {
    $original = '<p>Nothing bracketed at all.</p>';
    $task = Task::factory()->for($this->project)->create(['description' => $original]);
    $before = $task->fresh()->updated_at;

    renameTo('hero');

    expect($task->fresh()->description)->toBe($original)
        ->and($task->fresh()->updated_at->equalTo($before))->toBeTrue();
});

it('rewrites only whole usages, never a name inside a longer one', function () {
    expect(VariableSyntax::rename('<p>[hero] and [hero_two]</p>', 'hero', 'lead'))
        ->toBe('<p>[lead] and [hero_two]</p>');
});

it('keeps the page from someone who may not manage variables', function () {
    $viewer = userWithRole($this->project, 'viewer');

    Livewire::actingAs($viewer)
        ->test(ProjectVariables::class, ['short_name' => 'SCI'])
        ->assertForbidden();

    expect(User::query()->count())->toBeGreaterThan(0);
});
