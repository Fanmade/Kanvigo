<?php

use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\User;
use App\Support\Export\ExportOptions;
use App\Support\Export\MarkdownExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->member = userWithRole($this->project, 'member');
    $this->actingAs($this->member);
});

/** The heading lines of a rendered document, in order. */
function headingLines(string $markdown): array
{
    return array_values(array_filter(
        explode("\n", $markdown),
        static fn (string $line): bool => str_starts_with($line, '#'),
    ));
}

/** The rendered Markdown for an item, with descendants on and metadata off. */
function exportedSubtree(Task|Doc $item, ?int $depth = null, bool $canceled = false, bool $drafts = false): string
{
    return app(MarkdownExporter::class)->render($item, new ExportOptions(
        metadata: false,
        descendants: true,
        depth: $depth,
        canceled: $canceled,
        drafts: $drafts,
    ));
}

describe('the subtree', function () {
    it('stays at the item alone unless descendants are asked for', function () {
        $task = Task::factory()->for($this->project)->create(['title' => 'Parent', 'description' => null]);
        Task::factory()->for($this->project)->childOf($task)->create(['title' => 'Child']);

        $markdown = app(MarkdownExporter::class)->render($task, new ExportOptions(metadata: false));

        expect($markdown)->toBe("# Parent\n");
    });

    it('nests headings one level per level of the tree', function () {
        $root = Task::factory()->for($this->project)->create(['title' => 'Root']);
        $child = Task::factory()->for($this->project)->childOf($root)->create(['title' => 'Child']);
        Task::factory()->for($this->project)->childOf($child)->create(['title' => 'Grandchild']);

        expect(headingLines(exportedSubtree($root)))->toBe(['# Root', '## Child', '### Grandchild']);
    });

    it('clamps headings at six levels and keeps everything deeper there', function () {
        // Task nesting is capped by configuration (3 by default); an instance
        // that raises it can nest past what Markdown can express.
        config(['kanvigo.tasks.max_depth' => 10]);

        $root = Task::factory()->for($this->project)->create(['title' => 'Level 0']);

        $parent = $root;
        foreach (range(1, 7) as $level) {
            $parent = Task::factory()->for($this->project)->childOf($parent)->create(['title' => 'Level '.$level]);
        }

        // Markdown has no seventh level: the tail flattens rather than emitting
        // headings a reader's parser would not understand.
        expect(headingLines(exportedSubtree($root)))->toBe([
            '# Level 0',
            '## Level 1',
            '### Level 2',
            '#### Level 3',
            '##### Level 4',
            '###### Level 5',
            '###### Level 6',
            '###### Level 7',
        ]);
    });

    it('walks depth-first in the order the subtask list shows', function () {
        $root = Task::factory()->for($this->project)->create(['title' => 'Root']);
        $first = Task::factory()->for($this->project)->childOf($root)->create(['title' => 'First']);
        Task::factory()->for($this->project)->childOf($first)->create(['title' => 'First child']);
        Task::factory()->for($this->project)->childOf($root)->create(['title' => 'Second']);

        expect(headingLines(exportedSubtree($root)))
            ->toBe(['# Root', '## First', '### First child', '## Second']);
    });

    it('gives each descendant a compact metadata line instead of front matter', function () {
        $assignee = User::factory()->create(['name' => 'Ada']);
        $type = TaskType::factory()->for($this->project)->create(['name' => 'Feature']);
        $root = Task::factory()->for($this->project)->create();
        $child = Task::factory()->for($this->project)->childOf($root)->create([
            'title' => 'Child',
            'task_type_id' => $type->id,
        ]);
        $child->assignees()->attach($assignee);
        $child->tags()->attach($this->project->tags()->create(['name' => 'export']));

        $markdown = exportedSubtree($root);

        expect($markdown)->toContain('*'.$child->reference.' · '.$child->status->value.' · Feature · @Ada · #export*')
            ->and(substr_count($markdown, '---'))->toBe(0);
    });

    it('limits the export to the chosen number of levels', function () {
        $root = Task::factory()->for($this->project)->create(['title' => 'Root']);
        $child = Task::factory()->for($this->project)->childOf($root)->create(['title' => 'Child']);
        Task::factory()->for($this->project)->childOf($child)->create(['title' => 'Grandchild']);

        $markdown = exportedSubtree($root, depth: 1);

        expect($markdown)->toContain('## Child')
            ->and($markdown)->not->toContain('Grandchild');
    });
});

describe('canceled tasks', function () {
    it('leaves a canceled subtask out by default, with everything below it', function () {
        $root = Task::factory()->for($this->project)->create();
        $canceled = Task::factory()->for($this->project)->childOf($root)->canceled()->create(['title' => 'Abandoned']);
        Task::factory()->for($this->project)->childOf($canceled)->create(['title' => 'Below the abandoned one']);
        Task::factory()->for($this->project)->childOf($root)->create(['title' => 'Live work']);

        $markdown = exportedSubtree($root);

        expect($markdown)->toContain('Live work')
            ->and($markdown)->not->toContain('Abandoned')
            ->and($markdown)->not->toContain('Below the abandoned one');
    });

    it('includes canceled subtasks when asked, marked as canceled', function () {
        $root = Task::factory()->for($this->project)->create();
        Task::factory()->for($this->project)->childOf($root)->canceled()->create(['title' => 'Abandoned']);

        $markdown = exportedSubtree($root, canceled: true);

        expect($markdown)->toContain('## Abandoned')
            ->and($markdown)->toContain('· Canceled');
    });
});

describe('draft docs', function () {
    it('leaves a nested draft out by default', function () {
        $root = Doc::factory()->for($this->project)->create(['is_public' => true]);
        Doc::factory()->for($this->project)->create(['title' => 'Still a draft', 'is_public' => false, 'parent_id' => $root->id]);
        Doc::factory()->for($this->project)->create(['title' => 'Published one', 'is_public' => true, 'parent_id' => $root->id]);

        $markdown = exportedSubtree($root);

        expect($markdown)->toContain('Published one')
            ->and($markdown)->not->toContain('Still a draft');
    });

    it('marks an included draft in its metadata line', function () {
        $root = Doc::factory()->for($this->project)->create(['is_public' => true]);
        $draft = Doc::factory()->for($this->project)->create(['title' => 'Still a draft', 'is_public' => false, 'parent_id' => $root->id]);

        $markdown = exportedSubtree($root, drafts: true);

        expect($markdown)->toContain('## Still a draft')
            ->and($markdown)->toContain('*'.$draft->reference.' · Draft*');
    });

    it('never exports a draft the viewer may not see, even when drafts are asked for', function () {
        $viewer = userWithRole($this->project, 'viewer');
        $root = Doc::factory()->for($this->project)->create(['is_public' => true]);
        Doc::factory()->for($this->project)->create(['title' => 'Still a draft', 'is_public' => false, 'parent_id' => $root->id]);

        $this->actingAs($viewer);

        expect(exportedSubtree($root, drafts: true))->not->toContain('Still a draft');
    });

    it('exports a directly-exported draft regardless of the descendants option', function () {
        $draft = Doc::factory()->for($this->project)->create(['title' => 'Root draft', 'is_public' => false]);

        expect(exportedSubtree($draft))->toContain('# Root draft');
    });
});
