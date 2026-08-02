<?php

use App\Enums\ExportAttachmentMode;
use App\Enums\ExportFileLayout;
use App\Livewire\Tasks\TaskView;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Support\Export\ExportBundle;
use App\Support\Export\ExportOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(config('attachments.disk'));

    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->member = userWithRole($this->project, 'member');
    $this->actingAs($this->member);

    $this->task = Task::factory()->for($this->project)->create([
        'title' => 'Export functionality',
        'description' => '<p>The item itself.</p>',
    ]);
});

/** A stored non-inline attachment on the item. */
function attachFile(Task $task, string $name = 'spec.pdf', string $contents = 'PDF-BYTES'): Attachment
{
    $attachment = Attachment::factory()->create([
        'attachable_id' => $task->getKey(),
        'attachable_type' => $task->getMorphClass(),
        'name' => $name,
        'size' => strlen($contents),
        'is_inline' => false,
    ]);

    Storage::disk($attachment->disk)->put($attachment->path, $contents);

    return $attachment;
}

/** The bundle's files for the task, carrying the attachments. */
function attachmentBundle(ExportFileLayout $layout = ExportFileLayout::Flat, bool $descendants = false): array
{
    return app(ExportBundle::class)->files(test()->task->fresh(), new ExportOptions(
        metadata: false,
        descendants: $descendants,
        bundle: $descendants,
        layout: $layout,
        attachments: ExportAttachmentMode::Files,
    ));
}

describe('carrying the files', function () {
    it('writes each attachment into its own directory and lists it under the item', function () {
        $attachment = attachFile($this->task);

        $files = attachmentBundle();
        $document = $files[strtolower($this->task->reference).'-export-functionality.md'];
        $path = 'attachments/'.$attachment->id.'-spec.pdf';

        expect($files)->toHaveKey($path)
            ->and($files[$path])->toBe('PDF-BYTES')
            ->and($document)->toContain('## Attachments')
            ->and($document)->toContain('- [spec.pdf]('.$path.') — ');
    });

    it('leaves inline images to the image setting', function () {
        attachInlineImage($this->task);

        $files = attachmentBundle();

        expect(array_keys($files))->toHaveCount(1)
            ->and($files[array_key_first($files)])->not->toContain('## Attachments');
    });

    it('lists each item its own files in a subtree export', function () {
        $child = Task::factory()->for($this->project)->childOf($this->task)->create(['title' => 'The MVP']);
        attachFile($this->task, 'root.pdf');
        attachFile($child, 'child.pdf');

        $files = attachmentBundle(descendants: true);
        $rootDocument = $files[strtolower($this->task->reference).'-export-functionality.md'];
        $childDocument = $files[strtolower($child->reference).'-the-mvp.md'];

        // Each file of a bundle is a document in its own right, so each lists
        // its own files at the same level.
        expect($rootDocument)->toContain('## Attachments')
            ->and($rootDocument)->toContain('root.pdf')
            ->and($rootDocument)->not->toContain('child.pdf')
            ->and($childDocument)->toContain('## Attachments')
            ->and($childDocument)->toContain('child.pdf');
    });

    it('lists a subtask\'s files a heading deeper in a single-document export', function () {
        $child = Task::factory()->for($this->project)->childOf($this->task)->create(['title' => 'The MVP']);
        attachFile($child, 'child.pdf');

        // Not a bundle: one document, so the child sits at "##" and its files at
        // "###" — the archive exists only to hold the files themselves.
        $files = app(ExportBundle::class)->files($this->task->fresh(), new ExportOptions(
            metadata: false,
            descendants: true,
            attachments: ExportAttachmentMode::Files,
        ));

        expect($files[array_key_first($files)])->toContain('### Attachments')
            ->and($files[array_key_first($files)])->toContain('child.pdf');
    });

    it('links out of a folder in the nested layout', function () {
        $child = Task::factory()->for($this->project)->childOf($this->task)->create(['title' => 'The MVP']);
        attachFile($child, 'child.pdf');

        $files = attachmentBundle(ExportFileLayout::Nested, descendants: true);
        $root = strtolower($this->task->reference).'-export-functionality/';

        expect($files[$root.strtolower($child->reference).'-the-mvp.md'])->toContain('](../attachments/');
    });

    it('says so rather than linking to nothing when the file is gone', function () {
        $attachment = attachFile($this->task);
        Storage::disk($attachment->disk)->delete($attachment->path);

        $document = attachmentBundle()[strtolower($this->task->reference).'-export-functionality.md'];

        expect($document)->toContain('- spec.pdf *file not exported*')
            ->and($document)->not->toContain('](attachments/');
    });

    it('leaves the section out entirely when nothing is attached', function () {
        expect(attachmentBundle()[strtolower($this->task->reference).'-export-functionality.md'])
            ->not->toContain('Attachments');
    });

    it('stays out of an export that did not ask for it', function () {
        attachFile($this->task);

        $files = app(ExportBundle::class)->files($this->task, new ExportOptions(metadata: false, bundle: true));

        expect(array_keys($files))->toHaveCount(1)
            ->and($files[array_key_first($files)])->not->toContain('Attachments');
    });
});

describe('the selector', function () {
    it('stays hidden until something in the export has a file attached', function () {
        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
            ->call('startExport')
            ->assertDontSeeHtml('data-test="export-attachments"');
    });

    it('appears once a file is attached, defaulting to leaving it behind', function () {
        attachFile($this->task);

        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
            ->call('startExport')
            ->assertSeeHtml('data-test="export-attachments"')
            ->assertSet('exportAttachments', 'none')
            ->assertSeeHtml('data-test="export-copy"');
    });

    it('forces an archive and withdraws Copy once the files travel', function () {
        attachFile($this->task);

        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
            ->call('startExport')
            ->set('exportAttachments', ExportAttachmentMode::Files->value)
            ->assertSeeHtml('data-test="export-attachments-notice"')
            ->assertDontSeeHtml('data-test="export-copy"')
            ->call('downloadExport')
            ->assertFileDownloaded(strtolower($this->task->reference).'-export-functionality.zip');
    });

    it('records the choice in the audit event and remembers it', function () {
        attachFile($this->task);

        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
            ->call('startExport')
            ->set('exportAttachments', ExportAttachmentMode::Files->value)
            ->call('downloadExport');

        $event = json_decode((string) DB::table('audit_outbox')->orderByDesc('id')->value('event'), true);

        expect($event['metadata']['attachments'])->toBe('files');

        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
            ->call('startExport')
            ->assertSet('exportAttachments', 'files');
    });
});
