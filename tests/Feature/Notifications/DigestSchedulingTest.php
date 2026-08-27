<?php

use App\Enums\DeliveryMode;
use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ActivityDigest;
use App\Notifications\ItemActivityMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->actor = userWithRole($this->project, 'member');
    $this->user = userWithRole($this->project, 'member');
    $this->user->forceFill(['email_verified_at' => Carbon::now()])->save();
    $this->user->setPreference(User::EMAIL_PREFERENCE_KEY, true);

    $this->task = Task::factory()->for($this->project)
        ->status(Status::Planned)
        ->create(['priority' => Priority::Low]);
    $this->task->subscribe($this->user);
});

/** Put the user on a digest schedule. */
function onDigest(DeliveryMode $mode = DeliveryMode::Daily): User
{
    test()->user->setPreference(User::EMAIL_MODE_PREFERENCE_KEY, $mode->value);

    return test()->user->fresh();
}

/** Produce one feed-worthy change the user is subscribed to. */
function stirActivity(Priority $priority = Priority::High): void
{
    Auth::login(test()->actor);
    test()->task->update(['priority' => $priority]);
    Auth::logout();
}

describe('who gets a digest', function () {
    it('mails a digest to an opted-in digest subscriber', function () {
        onDigest();
        stirActivity();

        Notification::fake();
        $this->artisan('notifications:send-digests')->assertSuccessful();

        Notification::assertSentTo($this->user->fresh(), ActivityDigest::class);
    });

    it('leaves immediate subscribers alone', function () {
        stirActivity();

        Notification::fake();
        $this->artisan('notifications:send-digests')->assertSuccessful();

        Notification::assertNotSentTo($this->user->fresh(), ActivityDigest::class);
    });

    it('leaves out anyone who has not switched e-mail on', function () {
        $this->user->setPreference(User::EMAIL_PREFERENCE_KEY, false);
        onDigest();
        stirActivity();

        Notification::fake();
        $this->artisan('notifications:send-digests')->assertSuccessful();

        Notification::assertNotSentTo($this->user->fresh(), ActivityDigest::class);
    });

    it('leaves out an unverified address', function () {
        $this->user->forceFill(['email_verified_at' => null])->save();
        onDigest();
        stirActivity();

        Notification::fake();
        $this->artisan('notifications:send-digests')->assertSuccessful();

        Notification::assertNotSentTo($this->user->fresh(), ActivityDigest::class);
    });

    it('sends nothing when there is nothing unread', function () {
        onDigest();

        Notification::fake();
        $this->artisan('notifications:send-digests')->assertSuccessful();

        Notification::assertNothingSent();
    });
});

describe('the interval', function () {
    it('does not send again before the interval has elapsed', function () {
        onDigest();
        stirActivity();

        $this->artisan('notifications:send-digests')->assertSuccessful();
        stirActivity(Priority::Highest);

        Notification::fake();
        $this->artisan('notifications:send-digests')->assertSuccessful();

        Notification::assertNotSentTo($this->user->fresh(), ActivityDigest::class);
    });

    it('sends again once a day has passed', function () {
        onDigest();
        stirActivity();

        $this->artisan('notifications:send-digests')->assertSuccessful();

        $this->user->fresh()->forceFill(['digest_sent_at' => Carbon::now()->subDays(2)])->save();
        stirActivity(Priority::Highest);

        Notification::fake();
        $this->artisan('notifications:send-digests')->assertSuccessful();

        Notification::assertSentTo($this->user->fresh(), ActivityDigest::class);
    });

    it('makes a weekly subscriber wait a week', function () {
        onDigest(DeliveryMode::Weekly);
        stirActivity();

        $this->artisan('notifications:send-digests')->assertSuccessful();

        $this->user->fresh()->forceFill(['digest_sent_at' => Carbon::now()->subDays(3)])->save();
        stirActivity(Priority::Highest);

        Notification::fake();
        $this->artisan('notifications:send-digests')->assertSuccessful();

        Notification::assertNotSentTo($this->user->fresh(), ActivityDigest::class);
    });

    it('advances the cursor even on a quiet interval', function () {
        onDigest();

        $this->artisan('notifications:send-digests')->assertSuccessful();

        expect($this->user->fresh()->digest_sent_at)->not->toBeNull();
    });
});

describe('what the digest contains', function () {
    it('bundles several updates into one mail', function () {
        onDigest();
        stirActivity();
        stirActivity(Priority::Highest);

        Notification::fake();
        $this->artisan('notifications:send-digests')->assertSuccessful();

        Notification::assertSentTo(
            $this->user->fresh(),
            ActivityDigest::class,
            static fn (ActivityDigest $digest): bool => $digest->notifications->count() === 2,
        );
        Notification::assertSentToTimes($this->user->fresh(), ActivityDigest::class, 1);
    });

    it('leaves out notifications already read in the app', function () {
        onDigest();
        stirActivity();

        $this->user->fresh()->unreadNotifications()->update(['read_at' => Carbon::now()]);

        Notification::fake();
        $this->artisan('notifications:send-digests')->assertSuccessful();

        Notification::assertNotSentTo($this->user->fresh(), ActivityDigest::class);
    });

    it('honours the per-level switches', function () {
        onDigest();
        $this->user->setPreference(User::EMAIL_TASKS_PREFERENCE_KEY, false);
        stirActivity();

        Notification::fake();
        $this->artisan('notifications:send-digests')->assertSuccessful();

        Notification::assertNotSentTo($this->user->fresh(), ActivityDigest::class);
    });
});

it('stops immediate mail for a digest subscriber', function () {
    $user = onDigest();
    $activity = $this->task->activities()->firstOrFail();

    expect((new ItemActivityMail($activity))->via($user))->toBe([]);

    $user->setPreference(User::EMAIL_MODE_PREFERENCE_KEY, DeliveryMode::Immediate->value);

    expect((new ItemActivityMail($activity))->via($user->fresh()))->toBe(['mail']);
});
