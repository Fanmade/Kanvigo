<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

/**
 * KAN-540: a long parent-task label must truncate inside its select instead of
 * stretching the create-task dialog into a horizontal scrollbar.
 */
it('does not overflow the create-task dialog with a very long parent task title', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $other = Project::factory()->create(['short_name' => 'XYZ']);
    joinProject($project, $user);
    joinProject($other, $user);

    $parent = Task::factory()->for($project)->create([
        'title' => 'An extremely long parent task title that would otherwise stretch the create task dialog far past its maximum width',
    ]);

    $this->actingAs($user);

    $page = visit("/ABC-{$parent->task_number}");
    $page->click('@new-subtask')
        ->assertVisible('@create-task-parent') // barrier: the dialog finished opening
        ->assertScript(<<<'JS'
            (() => {
                const select = document.querySelector('[data-test=create-task-parent]');
                const dialog = select.closest('dialog');

                return select.scrollWidth <= select.clientWidth
                    && dialog.scrollWidth <= dialog.clientWidth;
            })()
        JS)
        ->assertNoJavascriptErrors();
});
