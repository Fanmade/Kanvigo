<?php

use App\Models\Activity;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

/**
 * Age an existing activity row past the retention window.
 */
function ageActivity(Activity $activity, int $days): void
{
    Activity::query()->whereKey($activity->id)->update(['created_at' => now()->subDays($days)]);
}

it('prunes activity entries older than the retention window', function () {
    config()->set('kanvigo.activity.retention_days', 30);

    $task = Task::factory()->for(Project::factory())->create();
    $old = seedActivity($task, 'status_changed', 'status', 'todo', 'done');
    $recent = seedActivity($task, 'status_changed', 'status', 'done', 'todo');

    ageActivity($old, 40);

    artisan('activity:prune')->assertSuccessful();

    expect(Activity::whereKey($old->id)->exists())->toBeFalse()
        ->and(Activity::whereKey($recent->id)->exists())->toBeTrue();
});

it('keeps every activity entry when retention is disabled', function () {
    config()->set('kanvigo.activity.retention_days', 0);

    $task = Task::factory()->for(Project::factory())->create();
    $activity = seedActivity($task, 'status_changed', 'status', 'todo', 'done');
    ageActivity($activity, 400);

    $count = Activity::count();

    artisan('activity:prune')->assertSuccessful();

    expect(Activity::count())->toBe($count);
});

it('drops the comment links of a pruned activity entry', function () {
    config()->set('kanvigo.activity.retention_days', 30);

    $task = Task::factory()->for(Project::factory())->create();
    $activity = seedActivity($task, 'status_changed', 'status', 'todo', 'done');

    $comment = $task->comments()->create(['body' => '<p>see this</p>']);
    $comment->activities()->attach($activity);

    ageActivity($activity, 40);

    artisan('activity:prune')->assertSuccessful();

    expect(Activity::whereKey($activity->id)->exists())->toBeFalse()
        ->and($comment->fresh()->activities()->count())->toBe(0);
});

it('prunes notifications that were read but never dismissed', function () {
    config()->set('kanvigo.notifications.retention_days', 30);

    $user = User::factory()->create();
    $readOld = makeNotification($user, read: true);
    $readRecent = makeNotification($user, read: true);
    $unreadOld = makeNotification($user);

    Notification::withTrashed()->whereKey($readOld->id)->update(['read_at' => now()->subDays(40)]);
    Notification::withTrashed()->whereKey($unreadOld->id)->update(['created_at' => now()->subDays(400)]);

    artisan('model:prune', ['--model' => [Notification::class]])->assertSuccessful();

    // The read-and-forgotten one is gone for good; a still-unread notification
    // survives however old it is.
    expect(Notification::withTrashed()->whereKey($readOld->id)->exists())->toBeFalse()
        ->and(Notification::withTrashed()->whereKey($readRecent->id)->exists())->toBeTrue()
        ->and(Notification::withTrashed()->whereKey($unreadOld->id)->exists())->toBeTrue();
});

it('prunes dismissed notifications past the window and keeps recent ones', function () {
    config()->set('kanvigo.notifications.retention_days', 30);

    $user = User::factory()->create();
    $old = makeNotification($user);
    $recent = makeNotification($user);

    $old->delete();
    $recent->delete();
    Notification::withTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subDays(40)]);

    artisan('model:prune', ['--model' => [Notification::class]])->assertSuccessful();

    expect(Notification::withTrashed()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Notification::withTrashed()->whereKey($recent->id)->exists())->toBeTrue();
});

it('keeps every notification when retention is disabled', function () {
    config()->set('kanvigo.notifications.retention_days', 0);

    $user = User::factory()->create();
    $dismissed = makeNotification($user);
    $dismissed->delete();
    Notification::withTrashed()->whereKey($dismissed->id)->update(['deleted_at' => now()->subYears(2)]);

    artisan('model:prune', ['--model' => [Notification::class]])->assertSuccessful();

    expect(Notification::withTrashed()->whereKey($dismissed->id)->exists())->toBeTrue();
});
