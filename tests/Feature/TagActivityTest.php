<?php

use App\Livewire\Activity\ActivityFeed;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A signed-in project member and one of the project's tags.
 *
 * @return array{0: User, 1: Project, 2: Tag}
 */
function userProjectTag(): array
{
    $user = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $user);
    $tag = Tag::factory()->for($project)->create(['name' => 'Important', 'color' => 'sky']);

    test()->actingAs($user);

    return [$user, $project, $tag];
}

it('logs a rename against the project with user attribution', function () {
    [$user, $project, $tag] = userProjectTag();

    expect($tag->rename('Deprecated'))->toBeTrue();

    $activity = $project->activities()->where('action', 'tag_renamed')->sole();

    expect($tag->fresh()->name)->toBe('Deprecated')
        ->and($activity->old_value)->toBe('Important')
        ->and($activity->new_value)->toBe('Deprecated')
        ->and($activity->user_id)->toBe($user->id);
});

it('does not log a blank or unchanged rename', function () {
    [, $project, $tag] = userProjectTag();

    expect($tag->rename('  Important  '))->toBeFalse()
        ->and($tag->rename(''))->toBeFalse()
        ->and($project->activities()->where('action', 'tag_renamed')->count())->toBe(0);
});

it('logs a recolor against the project, capturing the tag name and colors', function () {
    [, $project, $tag] = userProjectTag();

    expect($tag->recolor('rose'))->toBeTrue();

    $activity = $project->activities()->where('action', 'tag_recolored')->sole();

    expect($tag->fresh()->color)->toBe('rose')
        ->and(json_decode((string) $activity->new_value, true))->toBe(['name' => 'Important', 'color' => 'rose'])
        ->and(json_decode((string) $activity->old_value, true)['color'])->toBe('sky');
});

it('does not log a no-op recolor', function () {
    [, $project, $tag] = userProjectTag();

    expect($tag->recolor('sky'))->toBeFalse()
        ->and($project->activities()->where('action', 'tag_recolored')->count())->toBe(0);
});

it('logs a deletion against the project and the entry survives the tag', function () {
    [, $project, $tag] = userProjectTag();
    $tagId = $tag->id;

    $tag->deleteWithActivity();

    expect(Tag::find($tagId))->toBeNull()
        ->and($project->activities()->where('action', 'tag_deleted')->sole()->old_value)->toBe('Important');
});

it('renders human-readable descriptions for tag management activities', function () {
    [$user, $project, $tag] = userProjectTag();

    $tag->rename('Deprecated');
    $tag->recolor('rose');
    $tag->deleteWithActivity();

    $descriptions = Livewire::actingAs($user)
        ->test(ActivityFeed::class, ['subject' => $project])
        ->instance()
        ->descriptions();

    expect($descriptions)->toContain('renamed the tag Important to Deprecated')
        ->and($descriptions)->toContain('changed the color of the tag Deprecated')
        ->and($descriptions)->toContain('deleted the tag Deprecated');
});
