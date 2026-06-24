<?php

use App\Git\PrState;
use App\Git\TaskGitLink;
use App\Models\Task;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists a git link and casts its columns', function () {
    $task = Task::factory()->create();

    $link = TaskGitLink::factory()->for($task)->merged()->create([
        'branch_name' => 'feature/abc-42-add-widget',
        'base_branch' => 'main',
        'pr_number' => 17,
    ]);

    $fresh = $link->refresh();

    expect($fresh->branch_name)->toBe('feature/abc-42-add-widget')
        ->and($fresh->base_branch)->toBe('main')
        ->and($fresh->pr_number)->toBe(17)
        ->and($fresh->pr_state)->toBe(PrState::Merged)
        ->and($fresh->opened_at)->not->toBeNull()
        ->and($fresh->merged_at)->not->toBeNull()
        ->and($fresh->task->is($task))->toBeTrue();
});

it('defaults the pr state to none for a freshly reserved branch', function () {
    $link = TaskGitLink::factory()->create();

    expect($link->refresh()->pr_state)->toBe(PrState::None)
        ->and($link->pr_url)->toBeNull()
        ->and($link->merged_at)->toBeNull();
});

it('allows only one git link per task', function () {
    $task = Task::factory()->create();
    TaskGitLink::factory()->for($task)->create();

    TaskGitLink::factory()->for($task)->create();
})->throws(QueryException::class);

it('deletes the git link when its task is deleted', function () {
    $link = TaskGitLink::factory()->create();

    $link->task->delete();

    expect(TaskGitLink::query()->whereKey($link->getKey())->exists())->toBeFalse();
});

it('exposes a label and color for every pr state', function () {
    foreach (PrState::cases() as $state) {
        expect($state->label())->toBeString()->not->toBe('')
            ->and($state->color())->toBeString()->not->toBe('');
    }
});

it('uses forge-conventional colors for the pr states', function () {
    expect(PrState::None->color())->toBe('zinc')
        ->and(PrState::Open->color())->toBe('green')
        ->and(PrState::Merged->color())->toBe('purple')
        ->and(PrState::Closed->color())->toBe('red');
});
