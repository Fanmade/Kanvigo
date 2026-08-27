<?php

use App\Actions\SetWaitingOn;
use App\Enums\WaitingScope;
use App\Livewire\Waiting\WaitingIndex;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Queries\WaitingTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC', 'title' => 'Alpha']);
    $this->me = userWithRole($this->project, 'member');
    $this->asker = userWithRole($this->project, 'member');
});

/** Put a task into the waiting state as `$by`, waiting on `$on`. */
function waitFor(Task $task, User $on, User $by): Task
{
    Auth::login($by);
    app(SetWaitingOn::class)->handle($task, $on);
    Auth::logout();

    return $task->fresh();
}

describe('the two tabs', function () {
    it('lists only the tasks waiting on me', function () {
        $mine = waitFor(Task::factory()->for($this->project)->create(), $this->me, $this->asker);
        $theirs = waitFor(Task::factory()->for($this->project)->create(), $this->asker, $this->me);
        $idle = Task::factory()->for($this->project)->create();

        Livewire::actingAs($this->me)
            ->test(WaitingIndex::class)
            ->assertSeeHtml('data-test="waiting-item-'.$mine->id.'"')
            ->assertDontSeeHtml('data-test="waiting-item-'.$theirs->id.'"')
            ->assertDontSeeHtml('data-test="waiting-item-'.$idle->id.'"');
    });

    it('lists only the tasks I am waiting on somebody for', function () {
        $mine = waitFor(Task::factory()->for($this->project)->create(), $this->me, $this->asker);
        $theirs = waitFor(Task::factory()->for($this->project)->create(), $this->asker, $this->me);

        Livewire::actingAs($this->me)
            ->test(WaitingIndex::class)
            ->set('tab', WaitingScope::ByMe->value)
            ->assertSeeHtml('data-test="waiting-item-'.$theirs->id.'"')
            ->assertDontSeeHtml('data-test="waiting-item-'.$mine->id.'"');
    });

    it('shows an empty state when nothing is waiting', function () {
        Livewire::actingAs($this->me)
            ->test(WaitingIndex::class)
            ->assertSeeHtml('data-test="waiting-empty"');
    });

    it('orders the oldest wait first', function () {
        $recent = waitFor(Task::factory()->for($this->project)->create(['title' => 'Recent']), $this->me, $this->asker);
        $recent->forceFill(['waiting_since' => now()->subDay()])->save();

        $oldest = waitFor(Task::factory()->for($this->project)->create(['title' => 'Oldest']), $this->me, $this->asker);
        $oldest->forceFill(['waiting_since' => now()->subMonth()])->save();

        $ids = app(WaitingTasks::class)->handle($this->me, WaitingScope::OnMe)->pluck('id')->all();

        expect($ids)->toBe([$oldest->id, $recent->id]);
    });
});

describe('what drops off the list', function () {
    it('excludes canceled and archived tasks', function () {
        $canceled = waitFor(Task::factory()->for($this->project)->create(), $this->me, $this->asker);
        $canceled->forceFill(['canceled_at' => now()])->save();

        $archived = waitFor(Task::factory()->for($this->project)->create(), $this->me, $this->asker);
        $archived->forceFill(['archived_at' => now()])->save();

        expect(app(WaitingTasks::class)->handle($this->me, WaitingScope::OnMe)->count())->toBe(0);
    });

    it('excludes tasks in projects the user can no longer see', function () {
        $other = Project::factory()->create(['short_name' => 'XYZ']);
        $stranger = userWithRole($other, 'member');
        waitFor(Task::factory()->for($other)->create(), $stranger, $stranger);

        expect(app(WaitingTasks::class)->handle($this->me, WaitingScope::OnMe)->count())->toBe(0);
    });
});

describe('the quick reply', function () {
    it('posts a comment and clears the wait, dropping the row', function () {
        $task = waitFor(Task::factory()->for($this->project)->create(), $this->me, $this->asker);

        Livewire::actingAs($this->me)
            ->test(WaitingIndex::class)
            ->call('startReply', $task->id)
            ->set('replyBody', '<p>Here is the answer.</p>')
            ->call('reply')
            ->assertHasNoErrors()
            ->assertDontSeeHtml('data-test="waiting-item-'.$task->id.'"');

        expect($task->comments()->count())->toBe(1)
            ->and($task->fresh()->waiting_on_user_id)->toBeNull();
    });

    it('requires a body', function () {
        $task = waitFor(Task::factory()->for($this->project)->create(), $this->me, $this->asker);

        Livewire::actingAs($this->me)
            ->test(WaitingIndex::class)
            ->call('startReply', $task->id)
            ->call('reply')
            ->assertHasErrors('replyBody');

        expect($task->fresh()->waiting_on_user_id)->toBe($this->me->id);
    });

    it('refuses to open a composer on a task the user cannot see', function () {
        $other = Project::factory()->create(['short_name' => 'XYZ']);
        $task = Task::factory()->for($other)->create();

        Livewire::actingAs($this->me)
            ->test(WaitingIndex::class)
            ->call('startReply', $task->id)
            ->assertForbidden();
    });
});

describe('the count badge', function () {
    it('counts the tasks waiting on the user', function () {
        expect($this->me->waitingOnMeCount())->toBe(0);

        waitFor(Task::factory()->for($this->project)->create(), $this->me, $this->asker);
        waitFor(Task::factory()->for($this->project)->create(), $this->me, $this->asker);
        waitFor(Task::factory()->for($this->project)->create(), $this->asker, $this->me);

        expect($this->me->fresh()->waitingOnMeCount())->toBe(2)
            ->and($this->asker->fresh()->waitingOnMeCount())->toBe(1);
    });
});

it('renders many waiting items without a query per item', function () {
    $queriesToRender = function (int $items): int {
        $project = Project::factory()->create();
        $me = userWithRole($project, 'member');

        for ($i = 0; $i < $items; $i++) {
            $asker = userWithRole($project, 'member');
            waitFor(Task::factory()->for($project)->create(), $me, $asker);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($me)->test(WaitingIndex::class)->html();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($queriesToRender(20))->toBeLessThanOrEqual($queriesToRender(2));
});
