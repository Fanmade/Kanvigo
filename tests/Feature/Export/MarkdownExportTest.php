<?php

use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\User;
use App\Models\Variable;
use App\Support\Export\ExportOptions;
use App\Support\Export\MarkdownExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->exporter = app(MarkdownExporter::class);
});

/** The rendered Markdown for a task or doc, with metadata on unless told otherwise. */
function exportedMarkdown(Task|Doc $item, bool $metadata = true): string
{
    return app(MarkdownExporter::class)->render($item, new ExportOptions(metadata: $metadata));
}

describe('the document', function () {
    it('renders the title as the top-level heading, followed by the description', function () {
        $task = Task::factory()->for($this->project)->create([
            'title' => 'Export functionality',
            'description' => '<p>Ship the <strong>MVP</strong> first.</p>',
        ]);

        $markdown = exportedMarkdown($task, metadata: false);

        expect($markdown)->toStartWith('# Export functionality')
            ->and($markdown)->toContain('Ship the **MVP** first.');
    });

    it('renders a task with no description as the heading alone', function () {
        $task = Task::factory()->for($this->project)->create(['title' => 'Bare', 'description' => null]);

        expect(exportedMarkdown($task, metadata: false))->toBe("# Bare\n");
    });

    it('converts headings, lists, quotes, code and tables', function () {
        $task = Task::factory()->for($this->project)->create(['description' => <<<'HTML'
            <h2>Plan</h2><ul><li><p>first</p></li><li><p>second</p></li></ul>
            <ol><li><p>one</p></li></ol><blockquote><p>quoted</p></blockquote>
            <pre><code>$x = 1;</code></pre>
            <table><thead><tr><th>A</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table>
            HTML]);

        $markdown = exportedMarkdown($task, metadata: false);

        expect($markdown)->toContain('## Plan')
            ->and($markdown)->toContain('- first')
            ->and($markdown)->toContain('1. one')
            ->and($markdown)->toContain('> quoted')
            ->and($markdown)->toContain('```')
            ->and($markdown)->toContain('| A |');
    });

    it('escapes literal Markdown characters in user text', function () {
        $task = Task::factory()->for($this->project)->create([
            'description' => '<p>2 * 3, snake_case_name and a # hash.</p>',
        ]);

        expect(exportedMarkdown($task, metadata: false))->toContain('2 \* 3, snake\_case\_name and a # hash.');
    });
});

describe('inline entities', function () {
    it('turns a cross-reference into a link to the item on this instance', function () {
        $target = Doc::factory()->for($this->project)->create();
        $task = Task::factory()->for($this->project)->create([
            'description' => '<p>See '.inlineReference($target).'.</p>',
        ]);

        $url = route('doc.show', ['short_name' => 'ABC', 'doc_number' => $target->doc_number]);

        expect(exportedMarkdown($task, metadata: false))->toContain('['.$target->reference.']('.$url.')');
    });

    it('renders a mention as a plain @name', function () {
        $user = User::factory()->create(['name' => 'Ada']);
        $task = Task::factory()->for($this->project)->create([
            'description' => '<p>Ask <span class="mention" data-type="mention" data-id="'.$user->id.'" data-label="Ada">@Ada</span>.</p>',
        ]);

        $markdown = exportedMarkdown($task, metadata: false);

        expect($markdown)->toContain('Ask @Ada.')
            ->and($markdown)->not->toContain('data-type');
    });

    it('renders a variable usage as its value, and an unset one as its name', function () {
        Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
        Variable::factory()->for($this->project)->create(['name' => 'villain', 'value' => null]);

        $task = Task::factory()->for($this->project)->create([
            'description' => '<p>[hero] versus [villain].</p>',
        ]);

        expect(exportedMarkdown($task, metadata: false))->toContain('Robin Hood versus villain.');
    });

    it('embeds an inline image by absolute URL', function () {
        $task = Task::factory()->for($this->project)->create([
            'description' => '<p><img src="/ABC/attachments/7/thumbnail" alt="Screenshot"></p>',
        ]);

        expect(exportedMarkdown($task, metadata: false))
            ->toContain('![Screenshot]('.rtrim(url('/'), '/').'/ABC/attachments/7/thumbnail)');
    });
});

describe('front matter', function () {
    it('lists the task fields and omits the empty ones', function () {
        $assignee = User::factory()->create(['name' => 'Ada']);
        $type = TaskType::factory()->for($this->project)->create(['name' => 'Feature']);
        $parent = Task::factory()->for($this->project)->create();
        $blocker = Task::factory()->for($this->project)->create();

        $task = Task::factory()->for($this->project)->childOf($parent)->create([
            'title' => 'Export functionality',
            'task_type_id' => $type->id,
            'due_date' => '2026-08-10',
        ]);
        $task->assignees()->attach($assignee);
        $task->addBlocker($blocker);
        $task->refresh();

        $markdown = exportedMarkdown($task);

        expect($markdown)->toStartWith("---\n")
            ->and($markdown)->toContain('reference: '.$task->reference)
            ->and($markdown)->toContain('title: Export functionality')
            ->and($markdown)->toContain('url: '.route('task.show', ['short_name' => 'ABC', 'task_number' => $task->task_number]))
            ->and($markdown)->toContain('status: '.$task->status->value)
            ->and($markdown)->toContain('type: Feature')
            ->and($markdown)->toContain('priority: '.$task->priority->name)
            ->and($markdown)->toContain('assignees: [Ada]')
            ->and($markdown)->toContain('due_date: 2026-08-10')
            ->and($markdown)->toContain('parent: '.$parent->reference)
            ->and($markdown)->toContain('blocked_by: ['.$blocker->reference.']')
            ->and($markdown)->toContain('exported_at: ')
            // Nothing tagged, nothing blocked: those keys stay out entirely.
            ->and($markdown)->not->toContain('tags:')
            ->and($markdown)->not->toContain('blocks:');
    });

    it('quotes a title that YAML would otherwise misread', function () {
        $task = Task::factory()->for($this->project)->create(['title' => 'Export: tasks & docs']);

        expect(exportedMarkdown($task))->toContain('title: "Export: tasks & docs"');
    });

    it('lists the doc fields, including its publication state', function () {
        $parent = Doc::factory()->for($this->project)->create();
        $doc = Doc::factory()->for($this->project)->create([
            'title' => 'Style guide',
            'is_public' => true,
            'parent_id' => $parent->id,
        ]);

        $markdown = exportedMarkdown($doc);

        expect($markdown)->toContain('reference: '.$doc->reference)
            ->and($markdown)->toContain('state: published')
            ->and($markdown)->toContain('parent: '.$parent->reference)
            ->and($markdown)->toContain('url: '.route('doc.show', ['short_name' => 'ABC', 'doc_number' => $doc->doc_number]))
            // A doc has no status, priority or dependencies to report.
            ->and($markdown)->not->toContain('status:')
            ->and($markdown)->not->toContain('priority:');
    });

    it('reports an unpublished doc as a draft', function () {
        $doc = Doc::factory()->for($this->project)->create(['is_public' => false]);

        expect(exportedMarkdown($doc))->toContain('state: draft');
    });

    it('leaves the front matter out when metadata is off', function () {
        $task = Task::factory()->for($this->project)->create(['title' => 'Bare']);

        expect(exportedMarkdown($task, metadata: false))->not->toContain('---');
    });
});

describe('the filename', function () {
    it('puts the reference first, then a lowercase slug of the title', function () {
        $task = Task::factory()->for($this->project)->create(['title' => 'Export Functionality']);

        expect($this->exporter->filename($task))->toBe(strtolower($task->reference).'-export-functionality.md');
    });

    it('transliterates non-ASCII titles', function () {
        $task = Task::factory()->for($this->project)->create(['title' => 'Über Größe']);

        expect($this->exporter->filename($task))->toBe(strtolower($task->reference).'-uber-grosse.md');
    });

    it('caps the name and never ends on a dash', function () {
        $task = Task::factory()->for($this->project)->create([
            'title' => str_repeat('very long title ', 10),
        ]);

        $filename = $this->exporter->filename($task);

        expect(strlen($filename))->toBeLessThanOrEqual(63)
            ->and($filename)->toEndWith('.md')
            ->and($filename)->not->toContain('-.md');
    });

    it('falls back to the reference alone when a title slugs to nothing', function () {
        $doc = Doc::factory()->for($this->project)->create(['title' => '???']);

        expect($this->exporter->filename($doc))->toBe(strtolower($doc->reference).'.md');
    });
});
