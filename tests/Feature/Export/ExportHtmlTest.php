<?php

use App\Enums\ExportFormat;
use App\Livewire\Tasks\TaskView;
use App\Models\Project;
use App\Models\Task;
use App\Support\Export\ExportBundle;
use App\Support\Export\ExportOptions;
use App\Support\Export\ExportRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->member = userWithRole($this->project, 'member');
    $this->actingAs($this->member);

    $this->task = Task::factory()->for($this->project)->create([
        'title' => 'Export functionality',
        'description' => '<p>Ship the <strong>MVP</strong> first.</p><ul><li><p>one</p></li></ul>',
    ]);
});

/** The rendered HTML for a task. */
function exportedHtml(Task $task, bool $metadata = true, bool $descendants = false): string
{
    return app(ExportRenderer::class)->render($task->fresh(), new ExportOptions(
        metadata: $metadata,
        descendants: $descendants,
        format: ExportFormat::Html,
    ));
}

describe('the page', function () {
    it('is a standalone document with its own title and styling', function () {
        $html = exportedHtml($this->task);

        expect($html)->toStartWith('<!DOCTYPE html>')
            ->and($html)->toContain('<title>'.$this->task->reference.' · Export functionality</title>')
            ->and($html)->toContain('<meta charset="utf-8">')
            ->and($html)->toContain('<style>')
            // Nothing is fetched from anywhere, so the file reads offline.
            ->and($html)->not->toContain('<link ')
            ->and($html)->not->toContain('<script');
    });

    it('renders the content as HTML rather than as Markdown source', function () {
        $html = exportedHtml($this->task, metadata: false);

        expect($html)->toContain('<h1>Export functionality</h1>')
            ->and($html)->toContain('<strong>MVP</strong>')
            ->and($html)->toContain('<li>one</li>')
            ->and($html)->not->toContain('# Export functionality');
    });

    it('turns the front matter into a definition list instead of stray dashes', function () {
        $html = exportedHtml($this->task);

        expect($html)->toContain('<dl class="metadata">')
            ->and($html)->toContain('<dt>reference</dt><dd>'.$this->task->reference.'</dd>')
            ->and($html)->not->toContain('---');
    });

    it('leaves the metadata block out when it was not asked for', function () {
        expect(exportedHtml($this->task, metadata: false))->not->toContain('<dl class="metadata">');
    });

    it('escapes a title that would otherwise be markup', function () {
        $task = Task::factory()->for($this->project)->create([
            'title' => 'Fix <script>alert(1)</script>',
            'description' => null,
        ]);

        $html = exportedHtml($task);

        expect($html)->not->toContain('<script>alert(1)</script>')
            ->and($html)->toContain('&lt;script&gt;');
    });

    it('carries the subtree, its headings and the inline metadata line', function () {
        $child = Task::factory()->for($this->project)->childOf($this->task)->create(['title' => 'The MVP']);

        $html = exportedHtml($this->task, metadata: false, descendants: true);

        expect($html)->toContain('<h2>The MVP</h2>')
            ->and($html)->toContain('<em>'.$child->reference);
    });

    it('keeps cross-references as links to the instance', function () {
        $other = Task::factory()->for($this->project)->create();
        $this->task->update(['description' => '<p>See '.inlineReference($other).'.</p>']);

        $url = route('task.show', ['short_name' => 'ABC', 'task_number' => $other->task_number]);

        expect(exportedHtml($this->task, metadata: false))->toContain('<a href="'.$url.'">'.$other->reference.'</a>');
    });
});

describe('choosing the format', function () {
    it('names the download .html', function () {
        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
            ->call('startExport')
            ->set('exportFormat', ExportFormat::Html->value)
            ->call('downloadExport')
            ->assertFileDownloaded(strtolower($this->task->reference).'-export-functionality.html');
    });

    it('copies the page to the clipboard like any other text', function () {
        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
            ->call('startExport')
            ->set('exportFormat', ExportFormat::Html->value)
            ->call('copyExport')
            ->assertDispatched('export-copied', function (string $_event, array $params): bool {
                return str_starts_with($params['markdown'], '<!DOCTYPE html>');
            });
    });

    it('records the format in the audit event', function () {
        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
            ->call('startExport')
            ->set('exportFormat', ExportFormat::Html->value)
            ->call('copyExport');

        $event = json_decode((string) DB::table('audit_outbox')->orderByDesc('id')->value('event'), true);

        expect($event['action'])->toBe('content_exported')
            ->and($event['metadata']['format'])->toBe('html');
    });

    it('is remembered like the other options', function () {
        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
            ->call('startExport')
            ->set('exportFormat', ExportFormat::Html->value)
            ->call('copyExport');

        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
            ->call('startExport')
            ->assertSet('exportFormat', 'html');
    });
});

describe('an HTML bundle', function () {
    it('writes .html files and links between them', function () {
        $child = Task::factory()->for($this->project)->childOf($this->task)->create(['title' => 'The MVP']);
        $this->task->update(['description' => '<p>See '.inlineReference($child).'.</p>']);

        $files = app(ExportBundle::class)->files($this->task->fresh(), new ExportOptions(
            metadata: false,
            descendants: true,
            bundle: true,
            format: ExportFormat::Html,
        ));

        $rootFile = strtolower($this->task->reference).'-export-functionality.html';
        $childFile = strtolower($child->reference).'-the-mvp.html';

        expect(array_keys($files))->toBe([$rootFile, $childFile])
            ->and($files[$rootFile])->toContain('<a href="'.$childFile.'">'.$child->reference.'</a>');
    });

    it('names the archive after the format it holds', function () {
        $filename = app(ExportBundle::class)->filename($this->task, new ExportOptions(
            bundle: true,
            format: ExportFormat::Html,
        ));

        expect($filename)->toBe(strtolower($this->task->reference).'-export-functionality.zip');
    });
});
