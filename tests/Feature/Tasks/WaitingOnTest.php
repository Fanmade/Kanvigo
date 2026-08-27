<?php

use App\Actions\SetWaitingOn;
use App\Livewire\Projects\ProjectBoard;
use App\Livewire\Tasks\TaskView;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ItemActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->member = userWithRole($this->project, 'member');
    $this->other = userWithRole($this->project, 'member');
    $this->viewer = userWithRole($this->project, 'viewer');
    $this->task = Task::factory()->for($this->project)->create();
});

/** Render the task page as the given user. */
function taskViewAs(User $user): Testable
{
    return Livewire::actingAs($user)->test(TaskView::class, [
        'short_name' => 'ABC',
        'task_number' => test()->task->task_number,
    ]);
}

describe('setting and clearing from the task page', function () {
    it('marks the task as waiting on a project member', function () {
        taskViewAs($this->member)->call('setWaitingOn', $this->other->id);

        $task = $this->task->fresh();

        expect($task->waiting_on_user_id)->toBe($this->other->id)
            ->and($task->waiting_since)->not->toBeNull();

        assertDatabaseHas('activities', [
            'subject_id' => $task->id,
            'action' => 'waiting_on_changed',
            'field' => 'waiting_on',
            'old_value' => null,
            'new_value' => $this->other->name,
        ]);
    });

    it('clears the wait again', function () {
        Auth::login($this->member);
        app(SetWaitingOn::class)->handle($this->task, $this->other);
        Auth::logout();

        taskViewAs($this->member)->call('clearWaitingOn');

        $task = $this->task->fresh();

        expect($task->waiting_on_user_id)->toBeNull()
            ->and($task->waiting_since)->toBeNull();

        assertDatabaseHas('activities', [
            'subject_id' => $task->id,
            'action' => 'waiting_on_changed',
            'old_value' => $this->other->name,
            'new_value' => null,
        ]);
    });

    it('ignores a user who is not a member of the project', function () {
        $outsider = User::factory()->create();

        taskViewAs($this->member)->call('setWaitingOn', $outsider->id);

        expect($this->task->fresh()->waiting_on_user_id)->toBeNull();
    });

    it('refuses to set the wait for someone who cannot edit the task', function () {
        taskViewAs($this->viewer)->call('setWaitingOn', $this->other->id)
            ->assertForbidden();

        expect($this->task->fresh()->waiting_on_user_id)->toBeNull();
    });

    it('subscribes and notifies the awaited member', function () {
        Notification::fake();

        taskViewAs($this->member)->call('setWaitingOn', $this->other->id);

        expect($this->task->fresh()->isSubscribedBy($this->other))->toBeTrue();

        Notification::assertSentTo($this->other, ItemActivity::class);
    });

    it('keeps the original waiting_since when the same member is set again', function () {
        Carbon::setTestNow('2026-08-01 09:00:00');
        taskViewAs($this->member)->call('setWaitingOn', $this->other->id);
        Carbon::setTestNow('2026-08-20 09:00:00');

        taskViewAs($this->member)->call('setWaitingOn', $this->other->id);

        expect($this->task->fresh()->waiting_since->toDateString())->toBe('2026-08-01');

        Carbon::setTestNow();
    });
});

describe('auto-clearing on a reply', function () {
    beforeEach(function () {
        Auth::login($this->member);
        app(SetWaitingOn::class)->handle($this->task, $this->other);
        Auth::logout();
    });

    it('clears the wait when the awaited member comments', function () {
        $this->task->comments()->create([
            'user_id' => $this->other->id,
            'body' => '<p>Here is my answer.</p>',
        ]);

        $task = $this->task->fresh();

        expect($task->waiting_on_user_id)->toBeNull()
            ->and($task->waiting_since)->toBeNull();

        assertDatabaseHas('activities', [
            'subject_id' => $task->id,
            'action' => 'waiting_on_changed',
            'old_value' => $this->other->name,
            'new_value' => null,
        ]);
    });

    it('leaves the wait alone when somebody else comments', function () {
        $this->task->comments()->create([
            'user_id' => $this->member->id,
            'body' => '<p>Any news?</p>',
        ]);

        expect($this->task->fresh()->waiting_on_user_id)->toBe($this->other->id);
    });

    it('leaves the wait alone when the comment is on another task', function () {
        $elsewhere = Task::factory()->for($this->project)->create();

        $elsewhere->comments()->create([
            'user_id' => $this->other->id,
            'body' => '<p>Unrelated.</p>',
        ]);

        expect($this->task->fresh()->waiting_on_user_id)->toBe($this->other->id);
    });

    it('leaves the wait alone for a system comment with no author', function () {
        $this->task->comments()->create([
            'user_id' => null,
            'body' => '<p>Imported.</p>',
        ]);

        expect($this->task->fresh()->waiting_on_user_id)->toBe($this->other->id);
    });
});

describe('the board card', function () {
    it('renders the waiting badge with the awaited name', function () {
        Auth::login($this->member);
        app(SetWaitingOn::class)->handle($this->task, $this->other);
        Auth::logout();

        Livewire::actingAs($this->member)
            ->test(ProjectBoard::class, ['short_name' => 'ABC'])
            ->assertSeeHtml('data-test="waiting-on-'.$this->task->id.'"')
            ->assertSee($this->other->name);
    });

    it('marks the badge overdue once the wait passes the reminder threshold', function () {
        config()->set('kanvigo.tasks.waiting_nudge_days', 7);

        Auth::login($this->member);
        app(SetWaitingOn::class)->handle($this->task, $this->other);
        Auth::logout();

        $this->task->forceFill(['waiting_since' => Carbon::now()->subDays(10)])->save();

        expect($this->task->fresh()->isWaitOverdue())->toBeTrue();

        Livewire::actingAs($this->member)
            ->test(ProjectBoard::class, ['short_name' => 'ABC'])
            ->assertSeeHtml('data-overdue="true"');
    });

    it('leaves a young wait un-highlighted', function () {
        config()->set('kanvigo.tasks.waiting_nudge_days', 7);

        Auth::login($this->member);
        app(SetWaitingOn::class)->handle($this->task, $this->other);
        Auth::logout();

        expect($this->task->fresh()->isWaitOverdue())->toBeFalse();

        Livewire::actingAs($this->member)
            ->test(ProjectBoard::class, ['short_name' => 'ABC'])
            ->assertDontSeeHtml('data-overdue="true"');
    });

    it('never marks a wait overdue when reminders are switched off', function () {
        config()->set('kanvigo.tasks.waiting_nudge_days', 0);

        Auth::login($this->member);
        app(SetWaitingOn::class)->handle($this->task, $this->other);
        Auth::logout();

        $this->task->forceFill(['waiting_since' => Carbon::now()->subYear()])->save();

        expect($this->task->fresh()->isWaitOverdue())->toBeFalse();
    });

    it('renders no badge for a task nobody is waiting on', function () {
        Livewire::actingAs($this->member)
            ->test(ProjectBoard::class, ['short_name' => 'ABC'])
            ->assertDontSeeHtml('data-test="waiting-on-'.$this->task->id.'"');
    });

    it('renders many waiting cards without a query per card', function () {
        $queriesToRender = function (int $waitingTasks): int {
            $project = Project::factory()->create();
            joinProject($project, $this->member);

            for ($i = 0; $i < $waitingTasks; $i++) {
                $awaited = userWithRole($project, 'member');
                $task = Task::factory()->for($project)->create();
                app(SetWaitingOn::class)->handle($task, $awaited);
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::actingAs($this->member)
                ->test(ProjectBoard::class, ['short_name' => $project->short_name])
                ->html();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        expect($queriesToRender(20))->toBeLessThanOrEqual($queriesToRender(2));
    });
});
