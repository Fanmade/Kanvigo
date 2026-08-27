<?php

use App\Enums\DeliveryMode;
use App\Livewire\Notifications\DeliverySettings;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ItemActivityMail;
use App\Notifications\WaitingReminderMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->user = userWithRole($this->project, 'member');
    $this->user->forceFill(['email_verified_at' => Carbon::now()])->save();
    $this->task = Task::factory()->for($this->project)->create();
});

/** An activity recorded on the given subject. */
function activityOn(Project|Task $subject): Activity
{
    $activity = new Activity(['action' => 'status_changed']);
    $activity->subject_type = $subject->getMorphClass();
    $activity->subject_id = $subject->getKey();
    $activity->project_id = $subject instanceof Task ? $subject->project_id : $subject->getKey();
    $activity->save();

    return $activity->setRelation('subject', $subject);
}

describe('the screen', function () {
    it('renders for a user who has never set a preference', function () {
        Livewire::actingAs($this->user)
            ->test(DeliverySettings::class)
            ->assertOk()
            ->assertSet('email', false)
            ->assertSet('emailTasks', true)
            ->assertSet('emailProjects', true);
    });

    it('persists the master switch and reads it back', function () {
        Livewire::actingAs($this->user)
            ->test(DeliverySettings::class)
            ->set('email', true);

        expect($this->user->fresh()->preference(User::EMAIL_PREFERENCE_KEY))->toBeTrue();

        Livewire::actingAs($this->user->fresh())
            ->test(DeliverySettings::class)
            ->assertSet('email', true);
    });

    it('persists the per-level switches', function () {
        Livewire::actingAs($this->user)
            ->test(DeliverySettings::class)
            ->set('email', true)
            ->set('emailProjects', false);

        expect($this->user->fresh()->preference(User::EMAIL_PROJECTS_PREFERENCE_KEY))->toBeFalse()
            ->and($this->user->fresh()->preference(User::EMAIL_TASKS_PREFERENCE_KEY, true))->toBeTrue();
    });

    it('persists the delivery mode', function () {
        Livewire::actingAs($this->user)
            ->test(DeliverySettings::class)
            ->assertSet('mode', DeliveryMode::Immediate->value)
            ->set('email', true)
            ->set('mode', DeliveryMode::Weekly->value);

        expect($this->user->fresh()->deliveryMode())->toBe(DeliveryMode::Weekly);
    });

    it('rejects a delivery mode that is not a real one', function () {
        $this->user->setPreference(User::EMAIL_MODE_PREFERENCE_KEY, DeliveryMode::Daily->value);

        Livewire::actingAs($this->user->fresh())
            ->test(DeliverySettings::class)
            ->set('mode', 'hourly')
            ->assertSet('mode', DeliveryMode::Daily->value);

        expect($this->user->fresh()->deliveryMode())->toBe(DeliveryMode::Daily);
    });

    it('warns when the address is not confirmed', function () {
        $this->user->forceFill(['email_verified_at' => null])->save();

        Livewire::actingAs($this->user->fresh())
            ->test(DeliverySettings::class)
            ->assertSeeHtml('data-test="delivery-unverified"');
    });

    it('does not warn a confirmed address', function () {
        Livewire::actingAs($this->user)
            ->test(DeliverySettings::class)
            ->assertDontSeeHtml('data-test="delivery-unverified"');
    });
});

describe('what the switches actually gate', function () {
    it('sends nothing while the master switch is off, whatever the levels say', function () {
        $this->user->setPreference(User::EMAIL_TASKS_PREFERENCE_KEY, true);
        $this->user->setPreference(User::EMAIL_PROJECTS_PREFERENCE_KEY, true);

        $mail = new ItemActivityMail(activityOn($this->task));

        expect($mail->via($this->user->fresh()))->toBe([]);
    });

    it('suppresses only the level that is switched off', function () {
        $this->user->setPreference(User::EMAIL_PREFERENCE_KEY, true);
        $this->user->setPreference(User::EMAIL_PROJECTS_PREFERENCE_KEY, false);
        $user = $this->user->fresh();

        expect((new ItemActivityMail(activityOn($this->task)))->via($user))->toBe(['mail'])
            ->and((new ItemActivityMail(activityOn($this->project)))->via($user))->toBe([]);
    });

    it('suppresses task mail when the task level is off', function () {
        $this->user->setPreference(User::EMAIL_PREFERENCE_KEY, true);
        $this->user->setPreference(User::EMAIL_TASKS_PREFERENCE_KEY, false);
        $user = $this->user->fresh();

        expect((new ItemActivityMail(activityOn($this->task)))->via($user))->toBe([])
            ->and((new ItemActivityMail(activityOn($this->project)))->via($user))->toBe(['mail']);
    });

    it('treats a waiting reminder as task-level', function () {
        $this->user->setPreference(User::EMAIL_PREFERENCE_KEY, true);
        $this->user->setPreference(User::EMAIL_TASKS_PREFERENCE_KEY, false);

        $reminder = new WaitingReminderMail($this->project, Task::query()->whereKey($this->task->id)->get());

        expect($reminder->via($this->user->fresh()))->toBe([]);
    });

    it('still refuses an unverified address that opted into everything', function () {
        $this->user->forceFill(['email_verified_at' => null])->save();
        $this->user->setPreference(User::EMAIL_PREFERENCE_KEY, true);

        expect((new ItemActivityMail(activityOn($this->task)))->via($this->user->fresh()))->toBe([]);
    });
});
