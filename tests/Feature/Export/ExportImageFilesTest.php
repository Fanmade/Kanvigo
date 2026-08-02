<?php

use App\Enums\ExportFileLayout;
use App\Enums\ExportImageMode;
use App\Livewire\Tasks\TaskView;
use App\Models\Project;
use App\Models\Task;
use App\Support\Export\ExportBundle;
use App\Support\Export\ExportOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(config('attachments.disk'));

    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->member = userWithRole($this->project, 'member');
    $this->actingAs($this->member);

    $this->task = Task::factory()->for($this->project)->create(['title' => 'Export functionality']);
});

/** The bundle's files for the task, with images written as files. */
function filesBundle(ExportFileLayout $layout = ExportFileLayout::Flat, bool $descendants = false): array
{
    return app(ExportBundle::class)->files(test()->task->fresh(), new ExportOptions(
        metadata: false,
        descendants: $descendants,
        bundle: $descendants,
        layout: $layout,
        images: ExportImageMode::Files,
    ));
}

describe('images as files', function () {
    it('writes the image into the archive and points the document at it', function () {
        $attachment = attachInlineImage($this->task, name: 'diagram.png');

        $files = filesBundle();
        $document = $files[strtolower($this->task->reference).'-export-functionality.md'];
        $image = 'images/'.$attachment->id.'-diagram.png';

        expect($files)->toHaveKey($image)
            ->and($document)->toContain('![Screenshot]('.$image.')')
            ->and($document)->not->toContain('http');
    });

    it('writes the original bytes, not a downscaled derivative', function () {
        $attachment = attachInlineImage($this->task, width: 300, height: 200);

        $files = filesBundle();
        $stored = $files['images/'.$attachment->id.'-'.$attachment->name];

        expect($stored)->toBe(Storage::disk($attachment->disk)->get($attachment->path))
            ->and(getimagesizefromstring($stored)[0])->toBe(300);
    });

    it('stores an image shown by two items only once', function () {
        $child = Task::factory()->for($this->project)->childOf($this->task)->create(['title' => 'The MVP']);
        $attachment = attachInlineImage($this->task);
        $child->update(['description' => $this->task->fresh()->description]);

        $files = filesBundle(descendants: true);

        expect(array_keys($files))->toHaveCount(3)
            ->and($files)->toHaveKey('images/'.$attachment->id.'-'.$attachment->name);
    });

    it('links out of a folder in the nested layout', function () {
        $child = Task::factory()->for($this->project)->childOf($this->task)->create(['title' => 'The MVP']);
        attachInlineImage($child);
        attachInlineImage($this->task);

        $files = filesBundle(ExportFileLayout::Nested, descendants: true);
        $root = strtolower($this->task->reference).'-export-functionality/';

        expect($files[$root.'index.md'])->toContain('](../images/')
            ->and($files[$root.strtolower($child->reference).'-the-mvp.md'])->toContain('](../images/');
    });

    it('says so rather than pointing at nothing when the file is gone', function () {
        $attachment = attachInlineImage($this->task);
        Storage::disk($attachment->disk)->delete($attachment->path);

        $document = filesBundle()[strtolower($this->task->reference).'-export-functionality.md'];

        expect($document)->toContain('*image not exported*')
            ->and($document)->toContain(route('attachments.thumbnail', ['short_name' => 'ABC', 'attachment' => $attachment]));
    });

    it('sanitises a file name that would escape the images folder', function () {
        $attachment = attachInlineImage($this->task, name: '../../etc/passwd.png');

        $files = filesBundle();

        foreach (array_keys($files) as $path) {
            expect($path)->not->toContain('..');
        }

        expect($files)->toHaveKey('images/'.$attachment->id.'-etc-passwd.png');
        expect(array_keys($files))->each->not->toContain('/../');
    });
});

describe('delivery', function () {
    it('forces an archive even for a single item with no descendants', function () {
        attachInlineImage($this->task);

        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
            ->call('startExport')
            ->set('exportImages', ExportImageMode::Files->value)
            ->call('downloadExport')
            ->assertFileDownloaded(strtolower($this->task->reference).'-export-functionality.zip');
    });

    it('withdraws Copy to clipboard, and refuses it if called anyway', function () {
        attachInlineImage($this->task);

        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
            ->call('startExport')
            ->assertSeeHtml('data-test="export-copy"')
            ->set('exportImages', ExportImageMode::Files->value)
            ->assertDontSeeHtml('data-test="export-copy"')
            ->assertSeeHtml('data-test="export-files-notice"')
            ->call('copyExport')
            ->assertNotDispatched('export-copied');
    });

    it('records the mode in the audit event', function () {
        attachInlineImage($this->task);

        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
            ->call('startExport')
            ->set('exportImages', ExportImageMode::Files->value)
            ->call('downloadExport');

        $event = json_decode((string) DB::table('audit_outbox')->orderByDesc('id')->value('event'), true);

        expect($event['metadata']['images'])->toBe('files');
    });
});
