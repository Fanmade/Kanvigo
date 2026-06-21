<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('navigates from a card breadcrumb badge to the ancestor task', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $project->members()->attach($user);

    $parent = Task::factory()->for($project)->create(['title' => 'Parent task']);
    $child = Task::factory()->for($project)->childOf($parent)->create(['title' => 'Child task']);

    $this->actingAs($user);

    $page = visit('/'.$project->short_name.'/board');

    $page->assertNoJavascriptErrors()
        ->assertSee($child->reference)
        ->click('@crumb-'.$child->id.'-'.$parent->id)
        ->assertPathIs('/'.$parent->reference)
        ->assertSee('Parent task')
        ->assertNoJavascriptErrors();
});
