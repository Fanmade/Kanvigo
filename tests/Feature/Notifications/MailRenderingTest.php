<?php

use App\Actions\SetWaitingOn;
use App\Enums\DeliveryMode;
use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/**
 * The templates are only compiled when a mail is actually sent, so these send
 * for real through the array transport rather than faking notifications — a
 * broken Blade view in `emails/` would otherwise pass every other test here.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC', 'title' => 'Alpha']);
    $this->actor = userWithRole($this->project, 'member');
    $this->recipient = userWithRole($this->project, 'member');
    $this->recipient->forceFill(['email_verified_at' => Carbon::now()])->save();
    $this->recipient->setPreference(User::EMAIL_PREFERENCE_KEY, true);
    $this->recipient = $this->recipient->fresh();

    $this->task = Task::factory()->for($this->project)
        ->status(Status::Planned)
        ->create(['title' => 'Pick a colour', 'priority' => Priority::Low]);
});

/** The bodies of everything the array transport captured. */
function sentMailBodies(): array
{
    return Mail::mailer()->getSymfonyTransport()->messages()
        ->map(static fn ($message): string => $message->getOriginalMessage()->toString())
        ->all();
}

it('renders the activity mail', function () {
    Auth::login($this->actor);
    $this->task->subscribe($this->recipient);
    $this->task->update(['priority' => Priority::High]);
    Auth::logout();

    $bodies = sentMailBodies();

    expect($bodies)->not->toBeEmpty()
        ->and($bodies[0])->toContain($this->task->reference)
        ->and($bodies[0])->toContain($this->actor->name);
});

it('renders the waiting reminder mail, listing every request', function () {
    config()->set('kanvigo.tasks.waiting_nudge_days', 7);

    Auth::login($this->actor);
    app(SetWaitingOn::class)->handle($this->task, $this->recipient);

    $second = Task::factory()->for($this->project)->create(['title' => 'Approve the copy']);
    app(SetWaitingOn::class)->handle($second, $this->recipient);
    Auth::logout();

    Task::query()->whereIn('id', [$this->task->id, $second->id])
        ->update(['waiting_since' => Carbon::now()->subDays(10)]);

    Mail::mailer()->getSymfonyTransport()->flush();

    $this->artisan('tasks:nudge-waiting')->assertSuccessful();

    $reminder = collect(sentMailBodies())
        ->first(static fn (string $body): bool => str_contains($body, 'Approve the copy'));

    expect($reminder)->not->toBeNull()
        ->and($reminder)->toContain($this->task->reference)
        ->and($reminder)->toContain($second->reference);
});

it('renders the digest mail, listing every unread update', function () {
    $this->recipient->setPreference(User::EMAIL_MODE_PREFERENCE_KEY, DeliveryMode::Daily->value);
    $this->task->subscribe($this->recipient);

    Auth::login($this->actor);
    $this->task->update(['priority' => Priority::High]);
    $this->task->update(['priority' => Priority::Highest]);
    Auth::logout();

    Mail::mailer()->getSymfonyTransport()->flush();

    $this->artisan('notifications:send-digests')->assertSuccessful();

    $digest = collect(sentMailBodies())
        ->first(static fn (string $body): bool => str_contains($body, 'Pick a colour'));

    expect($digest)->not->toBeNull()
        ->and($digest)->toContain($this->task->reference);
});

it('sends nothing at all to someone who has not opted in', function () {
    $stranger = userWithRole($this->project, 'member');
    $this->task->subscribe($stranger);

    Auth::login($this->actor);
    $this->task->update(['priority' => Priority::High]);
    Auth::logout();

    $bodies = sentMailBodies();

    expect(collect($bodies)->filter(static fn (string $b): bool => str_contains($b, (string) $stranger->email)))
        ->toBeEmpty();
});
