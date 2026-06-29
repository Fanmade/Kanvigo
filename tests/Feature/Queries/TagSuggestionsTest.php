<?php

use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Queries\TagSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Create a tag in the project and apply it to $usage freshly-made tasks, so its
 * usage_count is deterministic.
 */
function tagUsedBy(Project $project, string $name, int $usage): Tag
{
    $tag = Tag::findOrCreateForProject($project->id, $name);

    Task::factory()->for($project)->count($usage)->create()
        ->each(static fn (Task $task) => $task->tags()->attach($tag));

    return $tag;
}

it('ranks tags by usage then name', function () {
    $project = Project::factory()->create();
    tagUsedBy($project, 'rare', 1);
    tagUsedBy($project, 'popular', 3);

    $names = app(TagSuggestions::class)->handle($project->id)->pluck('name')->all();

    expect($names)->toBe(['popular', 'rare']);
});

it('excludes tags by id', function () {
    $project = Project::factory()->create();
    $alpha = tagUsedBy($project, 'alpha', 2);
    tagUsedBy($project, 'beta', 1);

    $names = app(TagSuggestions::class)->handle($project->id, excludeIds: [$alpha->id])->pluck('name')->all();

    expect($names)->toBe(['beta']);
});

it('excludes by lower-cased name, filters by search, and honors take', function () {
    $project = Project::factory()->create();
    tagUsedBy($project, 'Frontend', 3);
    tagUsedBy($project, 'Backend', 2);
    tagUsedBy($project, 'Docs', 1); // doesn't match "end"

    $names = app(TagSuggestions::class)->handle(
        $project->id,
        search: 'end',
        excludeNames: ['frontend'],
        take: 5,
    )->pluck('name')->all();

    expect($names)->toBe(['Backend']);
});

it('matches a synonym in the search', function () {
    $project = Project::factory()->create();
    tagUsedBy($project, 'Research', 1)->syncSynonyms(['Evaluation']);

    $names = app(TagSuggestions::class)->handle($project->id, search: 'eval')->pluck('name')->all();

    expect($names)->toContain('Research');
});

it('scopes suggestions to the given project', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    tagUsedBy($project, 'mine', 1);
    tagUsedBy($other, 'theirs', 5);

    $names = app(TagSuggestions::class)->handle($project->id)->pluck('name')->all();

    expect($names)->toBe(['mine']);
});
