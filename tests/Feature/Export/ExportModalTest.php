<?php

use App\Livewire\Docs\DocView;
use App\Livewire\Tasks\TaskView;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->member = userWithRole($this->project, 'member');
    $this->viewer = userWithRole($this->project, 'viewer');

    $this->task = Task::factory()->for($this->project)->create([
        'title' => 'Export functionality',
        'description' => '<p>Ship the MVP first.</p>',
    ]);
});

/** A TaskView test component for the task under test. */
function exportTaskView(): Testable
{
    return Livewire::actingAs(test()->member)
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => test()->task->task_number]);
}

/** The most recent audit outbox event, decoded. */
function lastExportAuditEvent(): array
{
    return json_decode((string) DB::table('audit_outbox')->orderByDesc('id')->value('event'), true);
}

describe('the modal', function () {
    it('offers export in the action menu and opens the dialog', function () {
        exportTaskView()
            ->assertSeeHtml('data-test="export-task"')
            ->assertSet('exporting', false)
            ->call('startExport')
            ->assertSet('exporting', true)
            ->assertSet('exportMetadata', true);
    });

    it('shows only the metadata option — the other controls arrive with their features', function () {
        exportTaskView()
            ->call('startExport')
            ->assertSeeHtml('data-test="export-metadata"')
            ->assertSeeHtml('data-test="export-copy"')
            ->assertSeeHtml('data-test="export-download"')
            ->assertDontSeeHtml('data-test="export-depth"')
            ->assertDontSeeHtml('data-test="export-format"');
    });

    it('offers export on a doc as well', function () {
        $doc = Doc::factory()->for($this->project)->create();

        Livewire::actingAs($this->member)
            ->test(DocView::class, ['short_name' => 'ABC', 'doc_number' => $doc->doc_number])
            ->assertSeeHtml('data-test="export-doc"')
            ->call('startExport')
            ->assertSet('exporting', true);
    });
});

describe('copying', function () {
    it('dispatches the rendered Markdown for the clipboard and closes the dialog', function () {
        $component = exportTaskView()->call('startExport')->call('copyExport');

        $component->assertDispatched('export-copied', function (string $_event, array $params): bool {
            return str_contains($params['markdown'], '# Export functionality')
                && str_contains($params['markdown'], 'Ship the MVP first.')
                && str_starts_with($params['markdown'], '---');
        })->assertSet('exporting', false);
    });

    it('leaves the front matter out when the metadata option is unchecked', function () {
        exportTaskView()
            ->call('startExport')
            ->set('exportMetadata', false)
            ->call('copyExport')
            ->assertDispatched('export-copied', function (string $_event, array $params): bool {
                return str_starts_with($params['markdown'], '# Export functionality');
            });
    });
});

describe('downloading', function () {
    it('streams the Markdown as a file named after the item', function () {
        $response = exportTaskView()->call('startExport')->call('downloadExport');

        $response->assertFileDownloaded(strtolower($this->task->reference).'-export-functionality.md');
    });
});

describe('authorization', function () {
    it('hides the export action from a viewer', function () {
        Livewire::actingAs($this->viewer)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
            ->assertDontSeeHtml('data-test="export-task"')
            ->assertDontSeeHtml('data-test="export-modal"');
    });

    it('refuses a viewer who calls the export action directly', function () {
        Livewire::actingAs($this->viewer)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
            ->call('copyExport')
            ->assertForbidden();

        expect(DB::table('audit_outbox')->where('event', 'like', '%content_exported%')->count())->toBe(0);
    });

    it('refuses a viewer trying to open the dialog', function () {
        Livewire::actingAs($this->viewer)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
            ->call('startExport')
            ->assertForbidden()
            ->assertSet('exporting', false);
    });

    it('grants export to member, admin and owner but not viewer', function (string $role, bool $allowed) {
        $user = userWithRole($this->project, $role);

        expect($user->hasScopedPermission('export-content', $this->project))->toBe($allowed);
    })->with([
        ['owner', true],
        ['admin', true],
        ['member', true],
        ['viewer', false],
    ]);
});

describe('the audit trail', function () {
    it('records one content_exported access event with the options used', function () {
        exportTaskView()->call('startExport')->call('copyExport');

        $event = lastExportAuditEvent();

        expect($event['action'])->toBe('content_exported')
            ->and($event['category'])->toBe('access')
            ->and($event['subject_type'])->toBe(Task::class)
            ->and($event['subject_id'])->toBe($this->task->id)
            ->and($event['actor_id'])->toBe($this->member->id)
            ->and($event['metadata']['format'])->toBe('markdown')
            ->and($event['metadata']['metadata'])->toBeTrue();
    });

    it('records the event for a download too', function () {
        exportTaskView()->call('startExport')->set('exportMetadata', false)->call('downloadExport');

        $event = lastExportAuditEvent();

        expect($event['action'])->toBe('content_exported')
            ->and($event['metadata']['metadata'])->toBeFalse();
    });

    it('keeps the export out of the user-facing activity feed', function () {
        exportTaskView()->call('startExport')->call('copyExport');

        expect($this->task->activities()->where('action', 'content_exported')->exists())->toBeFalse();
    });
});
