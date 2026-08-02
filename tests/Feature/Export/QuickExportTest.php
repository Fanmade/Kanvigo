<?php

use App\Enums\ExportImageMode;
use App\Livewire\CommandPalette;
use App\Livewire\Docs\DocView;
use App\Livewire\Tasks\TaskView;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(config('attachments.disk'));

    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->member = userWithRole($this->project, 'member');
    $this->viewer = userWithRole($this->project, 'viewer');
    $this->actingAs($this->member);

    $this->task = Task::factory()->for($this->project)->create([
        'title' => 'Export functionality',
        'description' => '<p>Ship the MVP first.</p>',
    ]);
});

/** The task page component, as the palette's event would find it. */
function quickExportView(): Testable
{
    return Livewire::actingAs(test()->member)
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => test()->task->task_number]);
}

describe('the palette entry', function () {
    it('knows it is on an item page from the route the palette mounted on', function () {
        $this->actingAs($this->member)
            ->get(route('task.show', ['short_name' => 'ABC', 'task_number' => $this->task->task_number]))
            ->assertOk()
            ->assertSee('Export this item');

        $this->actingAs($this->member)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Export this item');
    });

    it('offers the command when the page it was opened on is an item', function () {
        Livewire::actingAs($this->member)
            ->test(CommandPalette::class)
            ->set('onItemPage', true)
            ->set('contextShortName', 'ABC')
            ->assertSee('Export this item');
    });

    it('stays out of the palette away from an item page', function () {
        Livewire::actingAs($this->member)
            ->test(CommandPalette::class)
            ->set('onItemPage', false)
            ->set('contextShortName', 'ABC')
            ->assertDontSee('Export this item');
    });

    it('stays out for someone who may not export', function () {
        Livewire::actingAs($this->viewer)
            ->test(CommandPalette::class)
            ->set('onItemPage', true)
            ->set('contextShortName', 'ABC')
            ->assertDontSee('Export this item');
    });

    it('dispatches the export rather than opening the dialog', function () {
        Livewire::actingAs($this->member)
            ->test(CommandPalette::class)
            ->set('onItemPage', true)
            ->set('contextShortName', 'ABC')
            ->call('runAction', 'quick-export')
            ->assertDispatched('quick-export');
    });
});

describe('exporting without the dialog', function () {
    it('copies the item with the remembered options', function () {
        // A previous export turned the front matter off; the quick one honours it.
        quickExportView()
            ->call('startExport')
            ->set('exportMetadata', false)
            ->call('copyExport');

        quickExportView()
            ->call('quickExport')
            ->assertDispatched('export-copied', function (string $_event, array $params): bool {
                return str_starts_with($params['markdown'], '# Export functionality');
            })
            // Straight to the clipboard: the dialog never opens.
            ->assertSet('exporting', false);
    });

    it('records the export like any other', function () {
        quickExportView()->call('quickExport');

        $event = json_decode((string) DB::table('audit_outbox')->orderByDesc('id')->value('event'), true);

        expect($event['action'])->toBe('content_exported')
            ->and($event['subject_id'])->toBe($this->task->id);
    });

    it('downloads instead, and says so, when the remembered options need an archive', function () {
        attachInlineImage($this->task);

        // Remember a mode that cannot go on a clipboard.
        quickExportView()
            ->call('startExport')
            ->set('exportImages', ExportImageMode::Files->value)
            ->call('downloadExport');

        $component = quickExportView()->call('quickExport');

        $component->assertFileDownloaded(strtolower($this->task->reference).'-export-functionality.zip')
            ->assertDispatched('toast-show', function (string $_event, array $params): bool {
                return str_contains($params['slots']['text'], 'archive');
            })
            ->assertNotDispatched('export-copied');
    });

    it('refuses someone who may not export, even through the event', function () {
        Livewire::actingAs($this->viewer)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
            ->call('quickExport')
            ->assertForbidden();
    });

    it('starts from the defaults for a user who has never exported', function () {
        quickExportView()
            ->call('quickExport')
            ->assertDispatched('export-copied', function (string $_event, array $params): bool {
                // Metadata is on by default, so the front matter leads.
                return str_starts_with($params['markdown'], '---');
            });
    });

    it('works on a doc as well as a task', function () {
        $doc = Doc::factory()->for($this->project)->create(['title' => 'Style guide']);

        Livewire::actingAs($this->member)
            ->test(DocView::class, ['short_name' => 'ABC', 'doc_number' => $doc->doc_number])
            ->call('quickExport')
            ->assertDispatched('export-copied');
    });
});
