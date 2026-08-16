<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

/**
 * KAN-523: a comment holding an unwrappable line — a pasted log line in a code
 * block, or one very long "word" — must scroll or wrap inside its own box
 * instead of stretching the page into a horizontal scrollbar.
 */
$longLine = '2026/08/02 13:07:25 [error] 524349#524349: *13268 client intended to send too large body: 105589700 bytes, client: 79.245.210.109, server: do.reuterben.de, request: "POST /livewire-eaae8dff/update HTTP/2.0", host: "do.reuterben.de"';
$longWord = str_repeat('averylongunbreakabletoken', 20);

$noPageOverflow = '(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)()';

it('does not overflow the page with a long line in a rendered comment', function () use ($longLine, $longWord, $noPageOverflow) {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);

    $task = Task::factory()->for($project)->create();
    $task->comments()->create([
        'user_id' => $user->id,
        'body' => "<pre><code>{$longLine}</code></pre><p>{$longWord}</p>",
    ]);

    $this->actingAs($user);

    visit("/ABC-{$task->task_number}")
        ->assertVisible('@comment-composer-trigger') // barrier: the comment list rendered
        ->assertScript($noPageOverflow)
        ->assertNoJavascriptErrors();
});

it('does not overflow the page with a long line loaded into the comment editor', function () use ($longLine, $longWord, $noPageOverflow) {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);

    $task = Task::factory()->for($project)->create();
    $task->comments()->create([
        'user_id' => $user->id,
        'body' => "<pre><code>{$longLine}</code></pre><p>{$longWord}</p>",
    ]);

    $this->actingAs($user);

    visit("/ABC-{$task->task_number}")
        ->click('@edit-comment')
        ->assertVisible('[data-test=comment-edit-form] pre') // barrier: the editor loaded the comment
        ->assertScript($noPageOverflow)
        // The code block scrolls on its own instead of dragging the whole
        // editor content sideways.
        ->assertScript(<<<'JS'
            (() => {
                const content = document.querySelector("[data-test=comment-edit-form] [data-slot='content']");

                return content.scrollWidth <= content.clientWidth;
            })()
        JS)
        ->assertNoJavascriptErrors();
});
