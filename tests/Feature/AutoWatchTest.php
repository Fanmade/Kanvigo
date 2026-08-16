<?php

use App\Enums\Status;
use App\Livewire\Comments\CommentList;
use App\Livewire\Projects\ProjectBoard;
use App\Livewire\Subscriptions\SubscriptionToggle;
use App\Livewire\Tasks\CreateTaskModal;
use App\Livewire\Tasks\TaskView;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ItemActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->member);
    $this->task = Task::factory()->for($this->project)->create();
});

it('subscribes the creator of a task', function () {
    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->set('projectId', $this->project->id)
        ->set('title', 'Watch me')
        ->call('save')
        ->assertHasNoErrors();

    $created = Task::where('title', 'Watch me')->sole();

    expect($created->isSubscribedBy($this->member))->toBeTrue();
});

it('does not subscribe the creator of a project', function () {
    $this->actingAs($this->member);

    $project = Project::factory()->create();

    expect($project->isSubscribedBy($this->member))->toBeFalse();
});

it('subscribes the author of a comment', function () {
    Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->task])
        ->set('body', '<p>Looks good</p>')
        ->call('addComment')
        ->assertHasNoErrors();

    expect($this->task->isSubscribedBy($this->member))->toBeTrue();
});

it('keeps an unsubscribe on file so the same trigger does not undo it', function () {
    $this->task->autoSubscribe([$this->member->id]);
    $this->task->unsubscribe($this->member);

    // The same involvement that subscribed them the first time — commenting,
    // being assigned — must not drag them back in.
    Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->task])
        ->set('body', '<p>Still not interested</p>')
        ->call('addComment');

    $this->task->autoSubscribe([$this->member->id]);

    expect($this->task->isSubscribedBy($this->member))->toBeFalse();
});

it('resubscribes when the user asks for it explicitly', function () {
    $this->task->autoSubscribe([$this->member->id]);
    $this->task->unsubscribe($this->member);

    Livewire::actingAs($this->member)
        ->test(SubscriptionToggle::class, ['subscribable' => $this->task])
        ->call('toggle');

    expect($this->task->isSubscribedBy($this->member))->toBeTrue();
});

it('leaves an unsubscribed user out of the notification audience', function () {
    Notification::fake();

    $watcher = User::factory()->create();
    joinProject($this->project, $watcher);
    $this->task->autoSubscribe([$watcher->id]);
    $this->task->unsubscribe($watcher);

    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->call('moveTask', $this->task->id, Status::Done->value);

    Notification::assertNotSentTo($watcher, ItemActivity::class);
});

it('hides an unsubscribed item from the subscriptions list', function () {
    $this->task->autoSubscribe([$this->member->id]);

    expect($this->member->subscribedTasks()->pluck('tasks.id'))->toContain($this->task->id);

    $this->task->unsubscribe($this->member);

    expect($this->member->fresh()->subscribedTasks()->pluck('tasks.id'))->not->toContain($this->task->id);
});

it('does not notify the author about their own comment', function () {
    Notification::fake();

    Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->task])
        ->set('body', '<p>Talking to myself</p>')
        ->call('addComment');

    Notification::assertNotSentTo($this->member, ItemActivity::class);
});

it('subscribes an assignee only until they opt out', function () {
    $assignee = User::factory()->create();
    joinProject($this->project, $assignee);

    $view = Livewire::actingAs($this->member)
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number]);

    $view->set('assigneeIds', [$assignee->id]);
    expect($this->task->isSubscribedBy($assignee))->toBeTrue();

    $this->task->unsubscribe($assignee);

    $view->set('assigneeIds', [])->set('assigneeIds', [$assignee->id]);

    expect($this->task->isSubscribedBy($assignee))->toBeFalse();
});
