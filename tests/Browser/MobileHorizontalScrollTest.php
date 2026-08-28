<?php

use App\Models\Doc;
use App\Models\Invitation;
use App\Models\Note;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

/**
 * A page must never make the document itself scroll sideways on a phone: a row
 * of actions or a wide table has to wrap, truncate or scroll inside its own
 * container instead. This walks every top-level page on a 375px viewport and
 * reports each one whose document overflows, so a regression names the page.
 */
it('renders every page without horizontal document scroll on a phone', function () {
    $admin = User::factory()->admin()->create(['name' => 'Ada Administrator']);
    $member = User::factory()->create([
        'name' => 'Bartholomew Featherstonehaugh',
        'email' => 'bartholomew.featherstonehaugh@a-rather-long-example-domain.test',
    ]);

    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, [$admin, $member]);

    $task = Task::factory()->for($project)->create(['title' => 'Draft the outline']);
    $doc = Doc::factory()->for($project)->published()->create(['title' => 'Handbook']);
    Note::factory()->for($admin, 'user')->create();

    Invitation::forceCreate([
        'email' => 'someone.with.a.long.address@a-rather-long-example-domain.test',
        'token' => 'a-token',
        'invited_by' => $admin->id,
        'project_ids' => [],
        'expires_at' => now()->addDay(),
    ]);

    $paths = [
        '/dashboard',
        '/projects',
        '/board',
        '/notes',
        '/notifications',
        '/waiting',
        '/activity',
        '/invite',
        '/admin/users',
        '/admin/roles',
        '/settings/profile',
        '/settings/appearance',
        '/settings/security',
        '/settings/api-tokens',
        "/{$project->short_name}",
        "/{$project->short_name}/board",
        "/{$project->short_name}/docs",
        "/{$project->short_name}/tags",
        "/{$project->short_name}/roles",
        "/{$project->short_name}/variables",
        "/{$project->short_name}/task-types",
        "/{$project->short_name}-{$task->task_number}",
        "/{$project->short_name}-D{$doc->doc_number}",
    ];

    $this->actingAs($admin);

    $overflowing = [];

    foreach ($paths as $path) {
        $overflow = visit($path)->on()->mobile()->script(
            '(() => { const el = document.documentElement; return el.scrollWidth - el.clientWidth; })()',
        );

        if ($overflow > 0) {
            $overflowing[] = "{$path} (+{$overflow}px)";
        }
    }

    expect($overflowing)->toBe([]);
});
