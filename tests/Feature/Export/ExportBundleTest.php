<?php

use App\Enums\ExportFileLayout;
use App\Livewire\Tasks\TaskView;
use App\Models\Project;
use App\Models\Task;
use App\Support\Export\ExportBundle;
use App\Support\Export\ExportOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->member = userWithRole($this->project, 'member');
    $this->actingAs($this->member);

    $this->root = Task::factory()->for($this->project)->create(['title' => 'Export functionality']);
    $this->child = Task::factory()->for($this->project)->childOf($this->root)->create(['title' => 'The MVP']);
    $this->grandchild = Task::factory()->for($this->project)->childOf($this->child)->create(['title' => 'Image handling']);
});

/** The bundle's files for the root task, in the given layout. */
function bundleFiles(ExportFileLayout $layout = ExportFileLayout::Flat, bool $metadata = false): array
{
    return app(ExportBundle::class)->files(test()->root->fresh(), new ExportOptions(
        metadata: $metadata,
        descendants: true,
        bundle: true,
        layout: $layout,
    ));
}

describe('the files', function () {
    it('writes one file per item instead of one document', function () {
        $files = bundleFiles();

        expect(array_keys($files))->toBe([
            strtolower($this->root->reference).'-export-functionality.md',
            strtolower($this->child->reference).'-the-mvp.md',
            strtolower($this->grandchild->reference).'-image-handling.md',
        ]);
    });

    it('gives each file its own item and nothing of the others', function () {
        $files = bundleFiles();
        $childFile = $files[strtolower($this->child->reference).'-the-mvp.md'];

        // Its own content only — the other items appear as navigation links, not
        // as text.
        expect($childFile)->toStartWith('# The MVP')
            ->and($childFile)->not->toContain('## Image handling')
            ->and($childFile)->not->toContain('# Export functionality');
    });

    it('keeps the front matter in every file when metadata is on', function () {
        $files = bundleFiles(metadata: true);

        foreach ($files as $contents) {
            expect($contents)->toStartWith("---\n");
        }
    });

    it('nests a folder per item that has children when asked to', function () {
        expect(array_keys(bundleFiles(ExportFileLayout::Nested)))->toBe([
            strtolower($this->root->reference).'-export-functionality/index.md',
            strtolower($this->root->reference).'-export-functionality/'.strtolower($this->child->reference).'-the-mvp/index.md',
            strtolower($this->root->reference).'-export-functionality/'.strtolower($this->child->reference).'-the-mvp/'
                .strtolower($this->grandchild->reference).'-image-handling.md',
        ]);
    });

    it('respects the subtree filters, so a canceled branch is absent', function () {
        $canceled = Task::factory()->for($this->project)->childOf($this->root)->canceled()->create(['title' => 'Abandoned']);

        expect(array_keys(bundleFiles()))->not->toContain(strtolower($canceled->reference).'-abandoned.md');
    });
});

describe('the date prefix', function () {
    it('dates the archive but leaves the files inside a flat bundle plain', function () {
        Carbon::setTestNow('2026-08-02 13:00');

        $options = new ExportOptions(
            metadata: false,
            descendants: true,
            bundle: true,
            datePrefix: true,
        );

        // The archive is already dated, so repeating it on every file is noise.
        expect(app(ExportBundle::class)->filename($this->root, $options))
            ->toBe('2026-08-02_'.strtolower($this->root->reference).'-export-functionality.zip')
            ->and(array_keys(app(ExportBundle::class)->files($this->root, $options)))->toBe([
                strtolower($this->root->reference).'-export-functionality.md',
                strtolower($this->child->reference).'-the-mvp.md',
                strtolower($this->grandchild->reference).'-image-handling.md',
            ]);
    });

    it('dates only the top folder in a nested bundle', function () {
        Carbon::setTestNow('2026-08-02 13:00');

        $files = app(ExportBundle::class)->files($this->root, new ExportOptions(
            metadata: false,
            descendants: true,
            bundle: true,
            layout: ExportFileLayout::Nested,
            datePrefix: true,
        ));

        $top = '2026-08-02_'.strtolower($this->root->reference).'-export-functionality/';

        expect(array_keys($files))->toBe([
            $top.'index.md',
            $top.strtolower($this->child->reference).'-the-mvp/index.md',
            $top.strtolower($this->child->reference).'-the-mvp/'.strtolower($this->grandchild->reference).'-image-handling.md',
        ]);
    });

    it('leaves the names alone when the option is off', function () {
        Carbon::setTestNow('2026-08-02 13:00');

        expect(array_key_first(bundleFiles()))->toBe(strtolower($this->root->reference).'-export-functionality.md');
    });
});

describe('cross-references inside the bundle', function () {
    it('links to the file when the target travels along', function () {
        $this->root->update(['description' => '<p>See '.inlineReference($this->grandchild).'.</p>']);

        $files = bundleFiles();
        $rootFile = $files[strtolower($this->root->reference).'-export-functionality.md'];

        expect($rootFile)->toContain('['.$this->grandchild->reference.']('.strtolower($this->grandchild->reference).'-image-handling.md)');
    });

    it('keeps the absolute URL for a target that stays behind', function () {
        $outsider = Task::factory()->for($this->project)->create(['title' => 'Elsewhere']);
        $this->root->update(['description' => '<p>See '.inlineReference($outsider).'.</p>']);

        $url = route('task.show', ['short_name' => 'ABC', 'task_number' => $outsider->task_number]);

        expect(bundleFiles()[strtolower($this->root->reference).'-export-functionality.md'])
            ->toContain('['.$outsider->reference.']('.$url.')');
    });

    it('walks up out of a folder in the nested layout', function () {
        $this->grandchild->update(['description' => '<p>Back to '.inlineReference($this->root).'.</p>']);

        $files = bundleFiles(ExportFileLayout::Nested);
        $deepest = $files[array_key_last($files)];

        expect($deepest)->toContain('](../index.md)');
    });
});

describe('the archive', function () {
    it('packs the files into a readable zip', function () {
        $bytes = app(ExportBundle::class)->zip($this->root, new ExportOptions(
            metadata: false,
            descendants: true,
            bundle: true,
        ));

        $path = tempnam(sys_get_temp_dir(), 'export-test');
        file_put_contents($path, $bytes);

        $archive = new ZipArchive;
        $archive->open($path);

        $names = [];
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $names[] = $archive->getNameIndex($index);
        }

        $first = (string) $archive->getFromName(strtolower($this->child->reference).'-the-mvp.md');
        $archive->close();
        @unlink($path);

        expect($names)->toHaveCount(3)
            ->and($first)->toStartWith('# The MVP');
    });

    it('names the archive after the item, with the same stem as the single file', function () {
        $filename = app(ExportBundle::class)->filename($this->root, new ExportOptions(bundle: true));

        expect($filename)->toBe(strtolower($this->root->reference).'-export-functionality.zip');
    });
});

describe('the controls', function () {
    it('offers the bundle only once descendants are included', function () {
        $component = Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->root->task_number])
            ->call('startExport');

        $component->assertDontSeeHtml('data-test="export-bundle"')
            ->set('exportDescendants', true)
            ->assertSeeHtml('data-test="export-bundle"')
            ->assertSet('exportBundle', false)
            // The layout only matters once there are several files.
            ->assertDontSeeHtml('data-test="export-layout"')
            ->set('exportBundle', true)
            ->assertSeeHtml('data-test="export-layout"');
    });

    it('withdraws Copy to clipboard for a bundle', function () {
        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->root->task_number])
            ->call('startExport')
            ->set('exportDescendants', true)
            ->assertSeeHtml('data-test="export-copy"')
            ->set('exportBundle', true)
            ->assertDontSeeHtml('data-test="export-copy"')
            ->assertSeeHtml('data-test="export-download"');
    });

    it('refuses to copy a bundle even when the action is called directly', function () {
        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->root->task_number])
            ->call('startExport')
            ->set('exportDescendants', true)
            ->set('exportBundle', true)
            ->call('copyExport')
            ->assertNotDispatched('export-copied');
    });

    it('downloads a zip named after the item', function () {
        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->root->task_number])
            ->call('startExport')
            ->set('exportDescendants', true)
            ->set('exportBundle', true)
            ->call('downloadExport')
            ->assertFileDownloaded(strtolower($this->root->reference).'-export-functionality.zip');
    });

    it('records the bundle and its layout in the audit event', function () {
        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->root->task_number])
            ->call('startExport')
            ->set('exportDescendants', true)
            ->set('exportBundle', true)
            ->set('exportLayout', 'nested')
            ->call('downloadExport');

        $event = json_decode((string) DB::table('audit_outbox')->orderByDesc('id')->value('event'), true);

        expect($event['metadata']['bundle'])->toBeTrue()
            ->and($event['metadata']['layout'])->toBe('nested');
    });
});

describe('navigating between the files', function () {
    it('links a file up to its parent and down to what is nested under it', function () {
        $files = bundleFiles();

        $rootFile = $files[strtolower($this->root->reference).'-export-functionality.md'];
        $childFile = $files[strtolower($this->child->reference).'-the-mvp.md'];
        $leafFile = $files[strtolower($this->grandchild->reference).'-image-handling.md'];

        expect($rootFile)->toContain('*Below: [The MVP]('.strtolower($this->child->reference).'-the-mvp.md)*')
            ->and($rootFile)->not->toContain('*Up:')
            ->and($childFile)->toContain('*Up: [Export functionality]('.strtolower($this->root->reference).'-export-functionality.md)*')
            ->and($childFile)->toContain('*Below: [Image handling](')
            // A leaf has somewhere to go up to and nowhere to go down.
            ->and($leafFile)->toContain('*Up: [The MVP](')
            ->and($leafFile)->not->toContain('*Below:');
    });

    it('walks the directories in the nested layout', function () {
        $files = bundleFiles(ExportFileLayout::Nested);
        $root = strtolower($this->root->reference).'-export-functionality/';
        $childDirectory = $root.strtolower($this->child->reference).'-the-mvp/';

        expect($files[$childDirectory.'index.md'])
            ->toContain('*Up: [Export functionality](../index.md)*')
            ->toContain('*Below: [Image handling](')
            ->and($files[$root.'index.md'])
            ->toContain('*Below: [The MVP]('.strtolower($this->child->reference).'-the-mvp/index.md)*');
    });

    it('never links to an item the archive left out', function () {
        Task::factory()->for($this->project)->childOf($this->root)->canceled()->create(['title' => 'Abandoned']);

        $files = bundleFiles();

        expect($files[strtolower($this->root->reference).'-export-functionality.md'])->not->toContain('Abandoned');
    });

    it('says nothing at all for a single-file export', function () {
        $solo = Task::factory()->for($this->project)->create(['title' => 'On its own']);

        $files = app(ExportBundle::class)->files($solo, new ExportOptions(metadata: false, bundle: true));

        expect($files[array_key_first($files)])->not->toContain('*Up:')
            ->and($files[array_key_first($files)])->not->toContain('*Below:');
    });
});
