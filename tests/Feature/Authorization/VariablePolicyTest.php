<?php

use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->variable = Variable::factory()->for($this->project)->create();
});

it('lets a member manage variables', function (string $role) {
    $user = userWithRole($this->project, $role);

    expect($user->can('view', $this->variable))->toBeTrue()
        ->and($user->can('update', $this->variable))->toBeTrue()
        ->and($user->can('delete', $this->variable))->toBeTrue()
        ->and($user->can('manage-variables', $this->project))->toBeTrue();
})->with(['member', 'admin', 'owner']);

it('lets a viewer read variables but not change them', function () {
    $viewer = userWithRole($this->project, 'viewer');

    expect($viewer->can('view', $this->variable))->toBeTrue()
        ->and($viewer->can('update', $this->variable))->toBeFalse()
        ->and($viewer->can('delete', $this->variable))->toBeFalse()
        ->and($viewer->can('manage-variables', $this->project))->toBeFalse();
});

it('hides variables from a non-member', function () {
    $outsider = User::factory()->create();

    expect($outsider->can('view', $this->variable))->toBeFalse()
        ->and($outsider->can('update', $this->variable))->toBeFalse()
        ->and($outsider->can('delete', $this->variable))->toBeFalse();
});
