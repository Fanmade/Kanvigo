<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->user);
    $this->task = Task::factory()->for($this->project)->create();
});

it('keeps the comment editor collapsed until the trigger is clicked', function () {
    $this->actingAs($this->user);

    $page = visit('/'.$this->task->reference);

    // The heavy editor stays hidden behind an input-styled trigger so it doesn't
    // push existing comments below the fold — see KAN-201.
    $page->assertVisible('@comment-composer-trigger')
        ->assertMissing('@add-comment')
        ->click('@comment-composer-trigger')
        ->assertVisible('@add-comment')
        ->assertMissing('@comment-composer-trigger')
        ->assertNoJavascriptErrors();
});
