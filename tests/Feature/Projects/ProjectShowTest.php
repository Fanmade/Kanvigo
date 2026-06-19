<?php

use App\Enums\Priority;
use App\Enums\Status;
use App\Livewire\Projects\ProjectShow;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['description' => 'Project blurb']);
    $this->project->members()->attach($this->user);
});

it('caps and scrolls the project description', function () {
    Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->assertSeeHtml('max-h-96 overflow-y-auto');
});

it('shows the description and open story tasks', function () {
    $story = Story::factory()->for($this->project)->create();
    Task::factory()->for($story)->status(Status::ToDo)->create(['title' => 'Open task']);

    Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->assertOk()
        ->assertSee('Project blurb')
        ->assertSee('Open task');
});

it('separates open stories from completed ones', function () {
    $completed = Story::factory()->for($this->project)->create();
    Task::factory()->for($completed)->status(Status::Done)->create();

    $open = Story::factory()->for($this->project)->create();
    Task::factory()->for($open)->status(Status::ToDo)->create();

    $component = Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name]);

    expect($component->instance()->openStories()->pluck('id'))
        ->toContain($open->id)
        ->not->toContain($completed->id)
        ->and($component->instance()->completedStories()->pluck('id'))
        ->toContain($completed->id);
});

it('lists only unfinished tasks within open stories', function () {
    $story = Story::factory()->for($this->project)->create();
    Task::factory()->for($story)->status(Status::ToDo)->create(['title' => 'Keep me visible']);
    Task::factory()->for($story)->status(Status::Done)->create(['title' => 'Hide me away']);

    Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->assertSee('Keep me visible')
        ->assertDontSee('Hide me away');
});

it('excludes empty and fully-finished stories from the open list', function () {
    $empty = Story::factory()->for($this->project)->create();

    $done = Story::factory()->for($this->project)->create();
    Task::factory()->for($done)->status(Status::Done)->create();

    $open = Story::factory()->for($this->project)->create();
    Task::factory()->for($open)->status(Status::ToDo)->create();

    $ids = Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->instance()->openStories()->pluck('id');

    expect($ids)->toContain($open->id)
        ->not->toContain($empty->id)
        ->not->toContain($done->id);
});

it('creates a story from the overview with the default priority', function () {
    Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->set('storyTitle', 'Brand new story')
        ->call('createStory');

    $story = $this->project->stories()->where('title', 'Brand new story')->first();

    // The project page shares the creation action with the board, so a story made
    // here gets the same default priority as one made on the board.
    expect($story)->not->toBeNull()
        ->and($story->priority)->toBe(Priority::default());
});

it('renames the short name and redirects to the new url', function () {
    $this->project->update(['short_name' => 'OLD']);

    Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => 'OLD'])
        ->call('edit')
        ->set('short_name', 'new')
        ->call('save')
        ->assertRedirect(route('project.show', ['short_name' => 'NEW']));

    expect($this->project->fresh()->short_name)->toBe('NEW');
});

it('rejects a short name already taken by another project', function () {
    Project::factory()->create(['short_name' => 'TAKEN']);
    $this->project->update(['short_name' => 'MINE']);

    Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => 'MINE'])
        ->call('edit')
        ->set('short_name', 'TAKEN')
        ->call('save')
        ->assertHasErrors('short_name');

    expect($this->project->fresh()->short_name)->toBe('MINE');
});

it('forbids non-members', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->assertForbidden();
});
