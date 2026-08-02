<?php

use App\Enums\ExportFileLayout;
use App\Enums\ExportImageMode;
use App\Livewire\Projects\ProjectShow;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Support\Export\Exceptions\ProjectTooLargeToExport;
use App\Support\Export\ExportOptions;
use App\Support\Export\ProjectExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC', 'title' => 'Ironwood Ledger']);
    $this->admin = userWithRole($this->project, 'admin');
    $this->member = userWithRole($this->project, 'member');
    $this->actingAs($this->admin);

    $this->task = Task::factory()->for($this->project)->create(['title' => 'Export functionality']);
    $this->subtask = Task::factory()->for($this->project)->childOf($this->task)->create(['title' => 'The MVP']);
    $this->doc = Doc::factory()->for($this->project)->create(['title' => 'Style guide', 'is_public' => true]);
});

/** The project's export files with the given options. */
function projectFiles(array $overrides = []): array
{
    return app(ProjectExport::class)->files(test()->project, new ExportOptions(
        metadata: $overrides['metadata'] ?? false,
        canceled: $overrides['canceled'] ?? false,
        drafts: $overrides['drafts'] ?? false,
        layout: $overrides['layout'] ?? ExportFileLayout::Flat,
        images: ExportImageMode::Embed,
    ));
}

describe('what the archive holds', function () {
    it('writes every task and doc as its own file, split by kind', function () {
        expect(array_keys(projectFiles()))->toBe([
            'tasks/'.strtolower($this->task->reference).'-export-functionality.md',
            'tasks/'.strtolower($this->subtask->reference).'-the-mvp.md',
            'docs/'.strtolower($this->doc->reference).'-style-guide.md',
        ]);
    });

    it('nests the trees inside each kind when asked to', function () {
        $paths = array_keys(projectFiles(['layout' => ExportFileLayout::Nested]));

        expect($paths[0])->toBe('tasks/'.strtolower($this->task->reference).'-export-functionality/index.md')
            ->and($paths[1])->toContain('-export-functionality/'.strtolower($this->subtask->reference).'-the-mvp.md')
            ->and($paths[2])->toBe('docs/'.strtolower($this->doc->reference).'-style-guide.md');
    });

    it('links a cross-reference from a task to a doc across the archive', function () {
        $this->task->update(['description' => '<p>See '.inlineReference($this->doc).'.</p>']);

        $files = projectFiles();

        expect($files['tasks/'.strtolower($this->task->reference).'-export-functionality.md'])
            ->toContain('](../docs/'.strtolower($this->doc->reference).'-style-guide.md)');
    });

    it('applies the same filters as any other export', function () {
        $canceled = Task::factory()->for($this->project)->canceled()->create(['title' => 'Abandoned']);
        $draft = Doc::factory()->for($this->project)->create(['title' => 'Rough notes', 'is_public' => false]);

        $paths = implode(' ', array_keys(projectFiles()));

        expect($paths)->not->toContain('abandoned')
            ->and($paths)->not->toContain('rough-notes');

        $withEverything = implode(' ', array_keys(projectFiles(['canceled' => true, 'drafts' => true])));

        expect($withEverything)->toContain(strtolower($canceled->reference))
            ->and($withEverything)->toContain(strtolower($draft->reference));
    });

    it('names the archive after the project', function () {
        Carbon::setTestNow('2026-08-02 13:00');

        $export = app(ProjectExport::class);

        expect($export->filename($this->project, new ExportOptions))->toBe('abc-ironwood-ledger.zip')
            ->and($export->filename($this->project, new ExportOptions(datePrefix: true)))
            ->toBe('2026-08-02_abc-ironwood-ledger.zip');
    });

    it('packs into a readable zip', function () {
        $bytes = app(ProjectExport::class)->zip($this->project, new ExportOptions(metadata: false));

        $path = tempnam(sys_get_temp_dir(), 'project-export');
        file_put_contents($path, $bytes);

        $archive = new ZipArchive;
        $archive->open($path);
        $count = $archive->numFiles;
        $archive->close();
        @unlink($path);

        expect($count)->toBe(3);
    });
});

describe('the size guard', function () {
    it('refuses a project with more items than one request should build', function () {
        config(['kanvigo.export.max_project_items' => 2]);

        app(ProjectExport::class)->files($this->project, new ExportOptions);
    })->throws(ProjectTooLargeToExport::class);

    it('counts the items the export would cover', function () {
        expect(app(ProjectExport::class)->itemCount($this->project, new ExportOptions))->toBe(3);
    });

    it('tells the user what the numbers are instead of failing silently', function () {
        config(['kanvigo.export.max_project_items' => 2]);

        Livewire::actingAs($this->admin)
            ->test(ProjectShow::class, ['short_name' => 'ABC'])
            ->call('startProjectExport')
            ->call('downloadProjectExport')
            ->assertDispatched('toast-show', function (string $_event, array $params): bool {
                return str_contains($params['slots']['text'], '3')
                    && str_contains($params['slots']['text'], '2');
            });
    });
});

describe('who may do it', function () {
    it('offers the action to an admin', function () {
        Livewire::actingAs($this->admin)
            ->test(ProjectShow::class, ['short_name' => 'ABC'])
            ->assertSeeHtml('data-test="export-project"')
            ->call('startProjectExport')
            ->assertSet('exportingProject', true);
    });

    it('keeps it from a member who may export single items', function () {
        expect($this->member->hasScopedPermission('export-content', $this->project))->toBeTrue()
            ->and($this->member->hasScopedPermission('export-project', $this->project))->toBeFalse();

        Livewire::actingAs($this->member)
            ->test(ProjectShow::class, ['short_name' => 'ABC'])
            ->assertDontSeeHtml('data-test="export-project"')
            ->call('startProjectExport')
            ->assertForbidden();
    });

    it('refuses the download to a member calling it directly', function () {
        Livewire::actingAs($this->member)
            ->test(ProjectShow::class, ['short_name' => 'ABC'])
            ->call('downloadProjectExport')
            ->assertForbidden();
    });
});

describe('downloading', function () {
    it('streams the archive and records a project_exported event', function () {
        Livewire::actingAs($this->admin)
            ->test(ProjectShow::class, ['short_name' => 'ABC'])
            ->call('startProjectExport')
            ->call('downloadProjectExport')
            ->assertFileDownloaded('abc-ironwood-ledger.zip');

        $event = json_decode((string) DB::table('audit_outbox')->orderByDesc('id')->value('event'), true);

        expect($event['action'])->toBe('project_exported')
            ->and($event['category'])->toBe('access')
            ->and($event['subject_type'])->toBe(Project::class)
            ->and($event['subject_id'])->toBe($this->project->id)
            ->and($event['metadata']['bundle'])->toBeTrue();
    });
});
