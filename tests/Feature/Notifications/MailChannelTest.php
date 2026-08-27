<?php

use App\Actions\SetWaitingOn;
use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ItemActivity;
use App\Notifications\ItemActivityMail;
use App\Notifications\WaitingReminderMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();

    $this->project = Project::factory()->create(['short_name' => 'ABC', 'title' => 'Alpha']);
    $this->actor = userWithRole($this->project, 'member');
    $this->watcher = userWithRole($this->project, 'member');
    $this->task = Task::factory()->for($this->project)->status(Status::Planned)->create(['priority' => Priority::Low]);
    $this->task->subscribe($this->watcher);
});

/** Opt a user into e-mail delivery. */
function optIntoMail(User $user): User
{
    $user->forceFill(['email_verified_at' => Carbon::now()])->save();
    $user->setPreference(User::EMAIL_PREFERENCE_KEY, true);

    return $user->fresh();
}

describe('opting in', function () {
    it('sends no mail to a subscriber who has not opted in', function () {
        Auth::login($this->actor);
        $this->task->update(['priority' => Priority::High]);

        Notification::assertSentTo($this->watcher, ItemActivity::class);
        Notification::assertNotSentTo($this->watcher, ItemActivityMail::class);
    });

    it('sends mail to a subscriber who has opted in', function () {
        optIntoMail($this->watcher);

        Auth::login($this->actor);
        $this->task->update(['priority' => Priority::High]);

        Notification::assertSentTo($this->watcher, ItemActivityMail::class);
    });

    it('never mails an unverified address', function () {
        $this->watcher->forceFill(['email_verified_at' => null])->save();
        $this->watcher->setPreference(User::EMAIL_PREFERENCE_KEY, true);

        $activity = $this->task->activities()->firstOrFail();

        expect((new ItemActivityMail($activity))->via($this->watcher->fresh()))->toBe([]);
    });

    it('still leaves the actor out of their own mail', function () {
        optIntoMail($this->actor);
        $this->task->subscribe($this->actor);

        Auth::login($this->actor);
        $this->task->update(['priority' => Priority::High]);

        Notification::assertNotSentTo($this->actor->fresh(), ItemActivityMail::class);
    });
});

describe('the message', function () {
    it('yields no channels for an opted-out recipient', function () {
        $activity = $this->task->activities()->firstOrFail();

        expect((new ItemActivityMail($activity))->via($this->watcher))->toBe([]);
    });

    it('yields the mail channel once opted in', function () {
        $watcher = optIntoMail($this->watcher);
        $activity = $this->task->activities()->firstOrFail();

        expect((new ItemActivityMail($activity))->via($watcher))->toBe(['mail']);
    });

    it('links a waiting hand-off to the answer queue rather than the task', function () {
        Auth::login($this->actor);
        app(SetWaitingOn::class)->handle($this->task, $this->watcher);
        Auth::logout();

        $activity = $this->task->activities()->where('action', 'waiting_on_changed')->firstOrFail();
        $mail = (new ItemActivityMail($activity))->toMail(optIntoMail($this->watcher));

        expect($mail->viewData['url'])->toBe(route('waiting.index'));
    });

    it('links ordinary activity to the item itself', function () {
        Auth::login($this->actor);
        $this->task->update(['priority' => Priority::High]);
        Auth::logout();

        $activity = $this->task->activities()->latest('id')->firstOrFail();
        $mail = (new ItemActivityMail($activity))->toMail(optIntoMail($this->watcher));

        expect($mail->viewData['url'])->toBe(route('task.show', [
            'short_name' => 'ABC',
            'task_number' => $this->task->task_number,
        ]));
    });

    it('names the actor and describes the change', function () {
        Auth::login($this->actor);
        $this->task->update(['priority' => Priority::High]);
        Auth::logout();

        $activity = $this->task->activities()->where('action', 'priority_changed')->firstOrFail();
        $mail = (new ItemActivityMail($activity))->toMail(optIntoMail($this->watcher));

        expect($mail->viewData['actor'])->toBe($this->actor->name)
            ->and($mail->viewData['reference'])->toBe($this->task->reference);
    });
});

describe('the waiting reminder mail', function () {
    it('is sent alongside the in-app reminder for opted-in people', function () {
        config()->set('kanvigo.tasks.waiting_nudge_days', 7);
        optIntoMail($this->watcher);

        Auth::login($this->actor);
        app(SetWaitingOn::class)->handle($this->task, $this->watcher);
        Auth::logout();

        $this->task->forceFill(['waiting_since' => Carbon::now()->subDays(10)])->save();

        $this->artisan('tasks:nudge-waiting')->assertSuccessful();

        Notification::assertSentTo($this->watcher->fresh(), WaitingReminderMail::class);
    });

    it('points at the answer queue and counts the requests', function () {
        $watcher = optIntoMail($this->watcher);
        $tasks = Task::query()->whereKey($this->task->id)->get();

        $mail = (new WaitingReminderMail($this->project, $tasks))->toMail($watcher);

        expect($mail->viewData['url'])->toBe(route('waiting.index'))
            ->and($mail->viewData['count'])->toBe(1);
    });
});

it('takes the language from the recipient rather than the session', function () {
    $watcher = optIntoMail($this->watcher);
    $watcher->setPreference('locale', 'de');

    expect($watcher->fresh()->preferredLocale())->toBe('de');

    $watcher->setPreference('locale', '');

    expect($watcher->fresh()->preferredLocale())->toBeNull();
});
