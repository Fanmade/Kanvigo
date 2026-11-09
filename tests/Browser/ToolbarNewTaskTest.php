<?php

use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->user);
    $this->actingAs($this->user);
});

it('opens the create-task modal from the toolbar button', function (string $path) {
    $doc = Doc::factory()->for($this->project)->published()->create(['title' => 'Style guide']);
    $task = Task::factory()->for($this->project)->create();

    $url = strtr($path, ['{doc}' => (string) $doc->doc_number, '{task}' => (string) $task->task_number]);

    visit($url)
        ->assertMissing('@create-task-title')
        ->click('@toolbar-new-task')
        ->assertVisible('@create-task-title')
        ->assertNoJavascriptErrors();
})->with([
    'dashboard' => '/dashboard',
    'project' => '/ABC',
    'task' => '/ABC-{task}',
    'doc' => '/ABC-D{doc}',
    'docs list' => '/ABC/docs',
]);
