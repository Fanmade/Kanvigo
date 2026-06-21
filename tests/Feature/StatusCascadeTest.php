<?php

use App\Actions\ChangeTaskStatus;
use App\Enums\CascadePreference;
use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create();
    $this->project->members()->attach($this->user);
    actingAs($this->user);
});

/**
 * Create a task in the shared project with the given status (and optional parent).
 */
function task(Status $status = Status::Planned, ?Task $parent = null): Task
{
    $factory = Task::factory()->for(test()->project)->status($status);

    if ($parent !== null) {
        $factory = $factory->childOf($parent);
    }

    return $factory->create();
}

function changeStatus(Task $task, Status $new, ?bool $cascade = null)
{
    return app(ChangeTaskStatus::class)->handle($task, $new, $cascade);
}

it('leaves children untouched when the parent moves to In progress', function () {
    $parent = task(Status::Planned);
    $child = task(Status::ToDo, $parent);

    changeStatus($parent, Status::InProgress);

    expect($parent->fresh()->status)->toBe(Status::InProgress)
        ->and($child->fresh()->status)->toBe(Status::ToDo);
});

it('cascades Done to open descendants when asked to', function () {
    $parent = task(Status::InProgress);
    $open = task(Status::ToDo, $parent);
    $done = task(Status::Done, $parent);

    $result = changeStatus($parent, Status::Done, cascade: true);

    expect($parent->fresh()->status)->toBe(Status::Done)
        ->and($open->fresh()->status)->toBe(Status::Done)
        ->and($done->fresh()->status)->toBe(Status::Done)
        ->and($result->cascadedChildren)->toBe(1); // the already-Done child is skipped
});

it('leaves descendants open when the cascade is declined', function () {
    $parent = task(Status::InProgress);
    $child = task(Status::ToDo, $parent);

    changeStatus($parent, Status::Done, cascade: false);

    expect($parent->fresh()->status)->toBe(Status::Done)
        ->and($child->fresh()->status)->toBe(Status::ToDo);
});

it('cascades through the whole open subtree, not just direct children', function () {
    $parent = task(Status::InProgress);
    $child = task(Status::ToDo, $parent);
    $grandchild = task(Status::Planned, $child);

    changeStatus($parent, Status::Canceled, cascade: true);

    expect($child->fresh()->status)->toBe(Status::Canceled)
        ->and($grandchild->fresh()->status)->toBe(Status::Canceled);
});

describe('the Done cascade honors the user preference when no explicit choice is given', function () {
    it('leaves children under "ask" (the Done default is to leave them)', function () {
        $this->user->setPreference(ChangeTaskStatus::PREFERENCE_KEY, CascadePreference::Ask->value);
        $parent = task(Status::InProgress);
        $child = task(Status::ToDo, $parent);

        changeStatus($parent, Status::Done);

        expect($child->fresh()->status)->toBe(Status::ToDo);
    });

    it('cascades under "always"', function () {
        $this->user->setPreference(ChangeTaskStatus::PREFERENCE_KEY, CascadePreference::Always->value);
        $parent = task(Status::InProgress);
        $child = task(Status::ToDo, $parent);

        changeStatus($parent, Status::Done);

        expect($child->fresh()->status)->toBe(Status::Done);
    });

    it('leaves children under "never"', function () {
        $this->user->setPreference(ChangeTaskStatus::PREFERENCE_KEY, CascadePreference::Never->value);
        $parent = task(Status::InProgress);
        $child = task(Status::ToDo, $parent);

        changeStatus($parent, Status::Done);

        expect($child->fresh()->status)->toBe(Status::ToDo);
    });
});

describe('the Cancel cascade honors the user preference when no explicit choice is given', function () {
    it('cancels children under "ask" (the Cancel default is to cascade)', function () {
        $this->user->setPreference(ChangeTaskStatus::PREFERENCE_KEY, CascadePreference::Ask->value);
        $parent = task(Status::InProgress);
        $child = task(Status::ToDo, $parent);

        changeStatus($parent, Status::Canceled);

        expect($child->fresh()->status)->toBe(Status::Canceled);
    });

    it('leaves children under "never"', function () {
        $this->user->setPreference(ChangeTaskStatus::PREFERENCE_KEY, CascadePreference::Never->value);
        $parent = task(Status::InProgress);
        $child = task(Status::ToDo, $parent);

        changeStatus($parent, Status::Canceled);

        expect($child->fresh()->status)->toBe(Status::ToDo);
    });

    it('cascades under "always"', function () {
        $this->user->setPreference(ChangeTaskStatus::PREFERENCE_KEY, CascadePreference::Always->value);
        $parent = task(Status::InProgress);
        $child = task(Status::ToDo, $parent);

        changeStatus($parent, Status::Canceled);

        expect($child->fresh()->status)->toBe(Status::Canceled);
    });
});

it('bumps a not-yet-started parent to In progress when a child starts', function () {
    $parent = task(Status::Planned);
    $child = task(Status::ToDo, $parent);

    $result = changeStatus($child, Status::InProgress);

    expect($parent->fresh()->status)->toBe(Status::InProgress)
        ->and($result->parentBumped)->toBeTrue()
        ->and($result->parentPreviousStatus)->toBe(Status::Planned->value);
});

it('does not bump a parent that is already In progress', function () {
    $parent = task(Status::InProgress);
    $child = task(Status::ToDo, $parent);

    $result = changeStatus($child, Status::InProgress);

    expect($result->parentBumped)->toBeFalse();
});

it('does not reopen a terminal parent when a child starts', function () {
    $parent = task(Status::Done);
    $child = task(Status::ToDo, $parent);

    $result = changeStatus($child, Status::InProgress);

    expect($parent->fresh()->status)->toBe(Status::Done)
        ->and($result->parentBumped)->toBeFalse();
});

it('reports when a closed child was the last open child of its parent', function () {
    $parent = task(Status::InProgress);
    $only = task(Status::ToDo, $parent);

    $result = changeStatus($only, Status::Canceled);

    expect($result->parentClosedOut)->toBeTrue();
});

it('does not report a closed-out parent while siblings remain open', function () {
    $parent = task(Status::InProgress);
    $first = task(Status::ToDo, $parent);
    task(Status::ToDo, $parent); // a still-open sibling

    $result = changeStatus($first, Status::Canceled);

    expect($result->parentClosedOut)->toBeFalse();
});

it('never changes the parent automatically when a child is canceled', function () {
    $parent = task(Status::InProgress);
    $only = task(Status::ToDo, $parent);

    changeStatus($only, Status::Canceled);

    expect($parent->fresh()->status)->toBe(Status::InProgress);
});

it('logs a status_changed activity for every task the cascade touches', function () {
    $parent = task(Status::InProgress);
    $child = task(Status::ToDo, $parent);

    changeStatus($parent, Status::Done, cascade: true);

    expect($parent->activities()->where('action', 'status_changed')->count())->toBe(1)
        ->and($child->activities()->where('action', 'status_changed')->count())->toBe(1);
});

it('applies the cascade atomically — a failure rolls back every change', function () {
    $parent = task(Status::InProgress);
    $child = task(Status::ToDo, $parent);
    $childId = $child->id;

    // Blow up midway, while the cascade saves the child, after the parent was changed.
    Task::saving(static function (Task $task) use ($childId): void {
        if ($task->getKey() === $childId && $task->status === Status::Done) {
            throw new RuntimeException('boom');
        }
    });

    expect(static fn () => changeStatus($parent, Status::Done, cascade: true))->toThrow(RuntimeException::class);

    expect($parent->fresh()->status)->toBe(Status::InProgress)
        ->and($child->fresh()->status)->toBe(Status::ToDo);
});

it('reverts a snapshot of statuses without recording activity', function () {
    $parent = task(Status::Planned);
    $child = task(Status::ToDo, $parent);

    $result = changeStatus($child, Status::InProgress);
    expect($parent->fresh()->status)->toBe(Status::InProgress);

    app(ChangeTaskStatus::class)->revert($result->parentBumpUndo());

    expect($parent->fresh()->status)->toBe(Status::Planned)
        ->and($parent->fresh()->activities()->where('action', 'status_changed')->count())->toBe(1);
});

it('is a no-op when the status is unchanged', function () {
    $task = task(Status::ToDo);

    $result = changeStatus($task, Status::ToDo);

    expect($result->changed())->toBeFalse()
        ->and($task->activities()->where('action', 'status_changed')->count())->toBe(0);
});
