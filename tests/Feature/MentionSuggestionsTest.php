<?php

use App\Livewire\Comments\CommentList;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Queries\MentionSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('builds the member and open-task autocomplete dataset for a project', function () {
    $project = Project::factory()->create();
    $alice = User::factory()->create(['name' => 'Alice']);
    joinProject($project, $alice);

    $task = Task::factory()->for($project)->create(['title' => 'Do the thing']);
    $canceled = Task::factory()->for($project)->canceled()->create();

    $data = app(MentionSuggestions::class)->handle($project);

    expect(collect($data['users'])->pluck('id'))->toContain($alice->id)
        ->and(collect($data['users'])->firstWhere('id', $alice->id))->toBe(['id' => $alice->id, 'name' => 'Alice'])
        ->and(collect($data['tasks'])->pluck('id'))->toContain($task->id)->not->toContain($canceled->id)
        ->and(collect($data['tasks'])->firstWhere('id', $task->id))
        ->toBe(['id' => $task->id, 'reference' => $task->reference, 'title' => 'Do the thing']);
});

it('serves the mention dataset to a project member', function () {
    $member = User::factory()->create(['name' => 'Amy']);
    $project = Project::factory()->create();
    joinProject($project, $member);
    $task = Task::factory()->for($project)->create(['title' => 'Build it']);

    $this->actingAs($member)
        ->getJson(route('project.mentionables', $project))
        ->assertOk()
        ->assertJsonFragment(['name' => 'Amy'])
        ->assertJsonFragment(['reference' => $task->reference, 'title' => 'Build it']);
});

it('forbids the mention dataset to a non-member', function () {
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->create())
        ->getJson(route('project.mentionables', $project))
        ->assertForbidden();
});

it('points the comment editor at the mention endpoint', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $user);
    $task = Task::factory()->for($project)->create();

    $html = Livewire::actingAs($user)
        ->test(CommentList::class, ['commentable' => $task])
        ->html();

    expect($html)
        ->toContain('data-mentionables-url')
        ->toContain('/'.$project->short_name.'/mentionables');
});
