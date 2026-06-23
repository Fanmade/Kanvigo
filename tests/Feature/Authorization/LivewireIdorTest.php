<?php

use App\Livewire\Activity\ActivityFeed;
use App\Livewire\Comments\CommentList;
use App\Livewire\Projects\ProjectBoard;
use App\Livewire\Projects\ProjectShow;
use App\Livewire\Subscriptions\SubscriptionToggle;
use App\Livewire\Tasks\TaskView;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();

    $this->project = Project::factory()->create();
    joinProject($this->project, $this->member);
    $this->task = Task::factory()->for($this->project)->create();

    // A project the member is NOT a part of — the target of the IDOR.
    $this->foreignProject = Project::factory()->create();
    $this->foreignTask = Task::factory()->for($this->foreignProject)->create();
});

/**
 * Tamper a locked property on the live component instance (backend mutation is
 * allowed even on locked props) and return the instance so the caller can invoke
 * a read computed and assert it re-authorizes.
 *
 * @param  array<string, mixed>  $tampered
 */
function tamper(object $component, array $tampered): object
{
    $instance = $component->instance();

    (function () use ($tampered): void {
        foreach ($tampered as $property => $value) {
            $this->{$property} = $value;
        }
    })->call($instance);

    return $instance;
}

// ---------------------------------------------------------------------------
// Layer 1 — #[Locked] blocks client-side tampering of the identifier props.
// ---------------------------------------------------------------------------

it('locks the ProjectShow short name', function () {
    Livewire::actingAs($this->member)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->set('shortName', $this->foreignProject->short_name);
})->throws(CannotUpdateLockedPropertyException::class);

it('locks the ProjectBoard short name', function () {
    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => $this->project->short_name])
        ->set('shortName', $this->foreignProject->short_name);
})->throws(CannotUpdateLockedPropertyException::class);

it('locks the TaskView identifiers', function () {
    Livewire::actingAs($this->member)
        ->test(TaskView::class, [
            'short_name' => $this->project->short_name,
            'task_number' => $this->task->task_number,
        ])
        ->set('taskNumber', $this->foreignTask->task_number);
})->throws(CannotUpdateLockedPropertyException::class);

it('locks the CommentList identifiers', function () {
    Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->task])
        ->set('morphSubjectId', $this->foreignTask->id);
})->throws(CannotUpdateLockedPropertyException::class);

it('locks the ActivityFeed identifiers', function () {
    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->task])
        ->set('morphSubjectId', $this->foreignTask->id);
})->throws(CannotUpdateLockedPropertyException::class);

it('locks the SubscriptionToggle identifiers', function () {
    Livewire::actingAs($this->member)
        ->test(SubscriptionToggle::class, ['subscribable' => $this->task])
        ->set('morphSubjectId', $this->foreignTask->id);
})->throws(CannotUpdateLockedPropertyException::class);

// ---------------------------------------------------------------------------
// Layer 2 — defence in depth: read computeds re-authorize, so even a tampered
// identifier (bypassing the lock) cannot disclose foreign data.
// ---------------------------------------------------------------------------

it('re-authorizes ProjectShow reads against tampered identifiers', function () {
    $instance = tamper(
        Livewire::actingAs($this->member)->test(ProjectShow::class, ['short_name' => $this->project->short_name]),
        ['shortName' => $this->foreignProject->short_name],
    );

    expect(fn () => $instance->project())->toThrow(AuthorizationException::class);
});

it('re-authorizes ProjectBoard reads against tampered identifiers', function () {
    $instance = tamper(
        Livewire::actingAs($this->member)->test(ProjectBoard::class, ['short_name' => $this->project->short_name]),
        ['shortName' => $this->foreignProject->short_name],
    );

    expect(fn () => $instance->project())->toThrow(AuthorizationException::class);
});

it('re-authorizes TaskView reads against tampered identifiers', function () {
    $instance = tamper(
        Livewire::actingAs($this->member)->test(TaskView::class, [
            'short_name' => $this->project->short_name,
            'task_number' => $this->task->task_number,
        ]),
        [
            'shortName' => $this->foreignProject->short_name,
            'taskNumber' => $this->foreignTask->task_number,
        ],
    );

    expect(fn () => $instance->task())->toThrow(AuthorizationException::class);
});

it('re-authorizes CommentList reads against tampered identifiers', function () {
    $instance = tamper(
        Livewire::actingAs($this->member)->test(CommentList::class, ['commentable' => $this->task]),
        ['morphSubjectId' => $this->foreignTask->id],
    );

    expect(fn () => $instance->commentable())->toThrow(AuthorizationException::class);
});

it('re-authorizes ActivityFeed reads against tampered identifiers', function () {
    $instance = tamper(
        Livewire::actingAs($this->member)->test(ActivityFeed::class, ['subject' => $this->task]),
        ['morphSubjectId' => $this->foreignTask->id],
    );

    expect(fn () => $instance->subject())->toThrow(AuthorizationException::class);
});

it('re-authorizes SubscriptionToggle reads against tampered identifiers', function () {
    $instance = tamper(
        Livewire::actingAs($this->member)->test(SubscriptionToggle::class, ['subscribable' => $this->task]),
        ['morphSubjectId' => $this->foreignTask->id],
    );

    expect(fn () => $instance->subscribable())->toThrow(AuthorizationException::class);
});
