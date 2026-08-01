<?php

use App\Livewire\Docs\DocView;
use App\Livewire\Tasks\TaskView;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Support\Export\ExportOptions;
use App\Support\Export\MarkdownExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->member = userWithRole($this->project, 'member');

    $this->task = Task::factory()->for($this->project)->create(['title' => 'Export functionality']);
});

/** A TaskView test component for the task under test. */
function preferenceTaskView(?Task $task = null): Testable
{
    $task ??= test()->task;

    return Livewire::actingAs(test()->member)
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $task->task_number]);
}

describe('remembering the options', function () {
    it('stores the options an export was taken with, on the user', function () {
        preferenceTaskView()
            ->call('startExport')
            ->set('exportMetadata', false)
            ->set('exportComments', true)
            ->call('copyExport');

        expect($this->member->fresh()->preference(ExportOptions::PREFERENCE_KEY))
            ->toMatchArray(['metadata' => false, 'comments' => true]);
    });

    it('opens the next export with them, on any item', function () {
        preferenceTaskView()
            ->call('startExport')
            ->set('exportMetadata', false)
            ->call('copyExport');

        $other = Task::factory()->for($this->project)->create();

        preferenceTaskView($other)
            ->call('startExport')
            ->assertSet('exportMetadata', false);
    });

    it('carries the habit across to a doc, since it is not per project', function () {
        preferenceTaskView()
            ->call('startExport')
            ->set('exportMetadata', false)
            ->call('copyExport');

        $doc = Doc::factory()->for($this->project)->create();

        Livewire::actingAs($this->member)
            ->test(DocView::class, ['short_name' => 'ABC', 'doc_number' => $doc->doc_number])
            ->call('startExport')
            ->assertSet('exportMetadata', false);
    });

    it('remembers a download the same way it remembers a copy', function () {
        preferenceTaskView()
            ->call('startExport')
            ->set('exportDatePrefix', true)
            ->call('downloadExport');

        expect($this->member->fresh()->preference(ExportOptions::PREFERENCE_KEY))
            ->toMatchArray(['date_prefix' => true]);
    });

    it('starts from the defaults for a user who has never exported', function () {
        preferenceTaskView()
            ->call('startExport')
            ->assertSet('exportMetadata', true)
            ->assertSet('exportDescendants', false)
            ->assertSet('exportDepth', 'all')
            ->assertSet('exportImages', 'embed')
            ->assertSet('exportDatePrefix', false);
    });
});

describe('restoring only what applies', function () {
    it('drops a remembered descendants choice on an item that has none', function () {
        $parent = Task::factory()->for($this->project)->create();
        Task::factory()->for($this->project)->childOf($parent)->create();

        preferenceTaskView($parent)
            ->call('startExport')
            ->set('exportDescendants', true)
            ->call('copyExport');

        // The task under test is childless, so there is nothing to include.
        preferenceTaskView()
            ->call('startExport')
            ->assertSet('exportDescendants', false);
    });

    it('clamps a remembered depth to a shallower subtree', function () {
        $deep = Task::factory()->for($this->project)->create();
        $child = Task::factory()->for($this->project)->childOf($deep)->create();
        Task::factory()->for($this->project)->childOf($child)->create();

        preferenceTaskView($deep)
            ->call('startExport')
            ->set('exportDescendants', true)
            ->set('exportDepth', '2')
            ->call('copyExport');

        $shallow = Task::factory()->for($this->project)->create();
        Task::factory()->for($this->project)->childOf($shallow)->create();

        preferenceTaskView($shallow)
            ->call('startExport')
            ->assertSet('exportDepth', 'all');
    });

    it('keeps a remembered "All" as "All" however deep the next subtree is', function () {
        $first = Task::factory()->for($this->project)->create();
        Task::factory()->for($this->project)->childOf($first)->create();

        preferenceTaskView($first)
            ->call('startExport')
            ->set('exportDescendants', true)
            ->set('exportDepth', 'all')
            ->call('copyExport');

        $second = Task::factory()->for($this->project)->create();
        $child = Task::factory()->for($this->project)->childOf($second)->create();
        Task::factory()->for($this->project)->childOf($child)->create();

        preferenceTaskView($second)
            ->call('startExport')
            ->assertSet('exportDepth', 'all')
            ->assertSet('exportDescendants', true);
    });

    it('ignores a stored image mode that is no longer valid', function () {
        $this->member->setPreference(ExportOptions::PREFERENCE_KEY, ['images' => 'holographic']);

        preferenceTaskView()
            ->call('startExport')
            ->assertSet('exportImages', 'embed');
    });
});

describe('the date prefix', function () {
    it('prepends the date to the download filename', function () {
        Carbon::setTestNow('2026-08-02 13:00');

        $filename = app(MarkdownExporter::class)->filename($this->task, datePrefix: true);

        expect($filename)->toBe('2026-08-02_'.strtolower($this->task->reference).'-export-functionality.md');
    });

    it('leaves the filename alone when it is off', function () {
        expect(app(MarkdownExporter::class)->filename($this->task))
            ->toBe(strtolower($this->task->reference).'-export-functionality.md');
    });

    it('names the downloaded file with the date when the option is on', function () {
        Carbon::setTestNow('2026-08-02 13:00');

        preferenceTaskView()
            ->call('startExport')
            ->set('exportDatePrefix', true)
            ->call('downloadExport')
            ->assertFileDownloaded('2026-08-02_'.strtolower($this->task->reference).'-export-functionality.md');
    });

    it('changes nothing about the exported content itself', function () {
        $withPrefix = app(MarkdownExporter::class)->render($this->task, new ExportOptions(metadata: false, datePrefix: true));
        $without = app(MarkdownExporter::class)->render($this->task, new ExportOptions(metadata: false));

        expect($withPrefix)->toBe($without);
    });
});
