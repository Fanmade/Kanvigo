<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->task = Task::factory()->for($this->project)->create();

    $this->member = User::factory()->create();
    joinProject($this->project, $this->member);

    $this->stranger = User::factory()->create();
});

it('grants project access only to members', function () {
    expect($this->member->can('view', $this->project))->toBeTrue()
        ->and($this->stranger->can('view', $this->project))->toBeFalse();
});

it('cascades project access to tasks', function () {
    expect($this->member->can('view', $this->task))->toBeTrue()
        ->and($this->stranger->can('view', $this->task))->toBeFalse();
});

it('gates project creation behind the capability', function () {
    $creator = User::factory()->canCreateProjects()->create();
    $regular = User::factory()->create();

    expect($creator->can('create-projects'))->toBeTrue()
        ->and($regular->can('create-projects'))->toBeFalse();
});

it('gates user invitations behind the capability', function () {
    $inviter = User::factory()->canInviteUsers()->create();
    $regular = User::factory()->create();

    expect($inviter->can('invite-users'))->toBeTrue()
        ->and($regular->can('invite-users'))->toBeFalse();
});
