<?php

use App\Enums\ExportImageMode;
use App\Livewire\Tasks\TaskView;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Support\Export\ExportOptions;
use App\Support\Export\MarkdownExporter;
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
});

/** A stored PNG of the given size, attached to the task and embedded in its description. */
function attachInlineImage(Task $task, int $width = 40, int $height = 30, string $name = 'diagram.png'): Attachment
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();

    $attachment = Attachment::factory()->inline()->create([
        'attachable_id' => $task->getKey(),
        'attachable_type' => $task->getMorphClass(),
        'name' => $name,
        'size' => strlen($bytes),
    ]);

    Storage::disk($attachment->disk)->put($attachment->path, $bytes);

    $task->update([
        'description' => '<p><img src="'.$attachment->thumbnailUrl(absolute: false).'" alt="Screenshot"></p>',
    ]);

    return $attachment;
}

/** The rendered Markdown for a task in the given image mode. */
function exportedWithImages(Task $task, ExportImageMode $mode): string
{
    return app(MarkdownExporter::class)->render(
        $task->fresh(),
        new ExportOptions(metadata: false, images: $mode),
    );
}

describe('the three modes', function () {
    it('embeds the absolute URL by default', function () {
        $task = Task::factory()->for($this->project)->create();
        $attachment = attachInlineImage($task);

        expect(exportedWithImages($task, ExportImageMode::Embed))
            ->toContain('![Screenshot]('.$attachment->thumbnailUrl().')');
    });

    it('writes a plain link, never an image, in link mode', function () {
        $task = Task::factory()->for($this->project)->create();
        $attachment = attachInlineImage($task);

        $markdown = exportedWithImages($task, ExportImageMode::Link);

        expect($markdown)->toContain('[Screenshot]('.$attachment->thumbnailUrl().')')
            ->and($markdown)->not->toContain('![');
    });

    it('falls back to the file name when the image has no alt text', function () {
        $task = Task::factory()->for($this->project)->create();
        $attachment = attachInlineImage($task, name: 'architecture.png');
        $task->update(['description' => '<p><img src="'.$attachment->thumbnailUrl(absolute: false).'"></p>']);

        expect(exportedWithImages($task, ExportImageMode::Link))->toContain('[architecture.png](');
    });

    it('inlines the picture as a data URI', function () {
        $task = Task::factory()->for($this->project)->create();
        attachInlineImage($task);

        $markdown = exportedWithImages($task, ExportImageMode::Inline);

        expect($markdown)->toContain('![Screenshot](data:image/png;base64,')
            ->and($markdown)->not->toContain('*image not embedded*');
    });

    it('downscales an inlined image to the configured maximum edge', function () {
        config(['kanvigo.export.image_max_edge' => 32]);

        $task = Task::factory()->for($this->project)->create();
        attachInlineImage($task, width: 200, height: 100);

        $markdown = exportedWithImages($task, ExportImageMode::Inline);

        preg_match('/base64,([^)]+)\)/', $markdown, $matches);
        $decoded = base64_decode($matches[1], true);
        [$width, $height] = getimagesizefromstring((string) $decoded);

        expect($width)->toBe(32)->and($height)->toBe(16);
    });
});

describe('the inline budget', function () {
    it('degrades to a link with a note once the budget is spent', function () {
        // A budget too small for any real image: the first one already breaks it.
        config(['kanvigo.export.inline_budget' => 10]);

        $task = Task::factory()->for($this->project)->create();
        $attachment = attachInlineImage($task, width: 200, height: 200);

        $markdown = exportedWithImages($task, ExportImageMode::Inline);

        expect($markdown)->toContain('[Screenshot]('.$attachment->thumbnailUrl().')')
            ->and($markdown)->toContain('*image not embedded*')
            ->and($markdown)->not->toContain('base64,');
    });

    it('notes rather than breaks when the file behind an image is gone', function () {
        $task = Task::factory()->for($this->project)->create();
        $attachment = attachInlineImage($task);
        Storage::disk($attachment->disk)->delete($attachment->path);

        expect(exportedWithImages($task, ExportImageMode::Inline))
            ->toContain('*image not embedded*');
    });
});

describe('the control', function () {
    it('stays hidden when the export has no images', function () {
        $task = Task::factory()->for($this->project)->create(['description' => '<p>Words only.</p>']);

        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $task->task_number])
            ->call('startExport')
            ->assertDontSeeHtml('data-test="export-images"');
    });

    it('appears once the content has an image, defaulting to embedding by URL', function () {
        $task = Task::factory()->for($this->project)->create();
        attachInlineImage($task);

        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $task->task_number])
            ->call('startExport')
            ->assertSeeHtml('data-test="export-images"')
            ->assertSet('exportImages', 'embed')
            ->assertDontSeeHtml('data-test="export-inline-warning"');
    });

    it('appears when only a descendant has an image, once descendants are included', function () {
        $task = Task::factory()->for($this->project)->create(['description' => '<p>Words only.</p>']);
        $child = Task::factory()->for($this->project)->childOf($task)->create();
        attachInlineImage($child);

        $component = Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $task->task_number])
            ->call('startExport');

        $component->assertDontSeeHtml('data-test="export-images"')
            ->set('exportDescendants', true)
            ->assertSeeHtml('data-test="export-images"');
    });

    it('warns about the size before copying an export with embedded images', function () {
        $task = Task::factory()->for($this->project)->create();
        attachInlineImage($task);

        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $task->task_number])
            ->call('startExport')
            ->set('exportImages', ExportImageMode::Inline->value)
            ->assertSeeHtml('data-test="export-inline-warning"')
            // Warned, not blocked: the size is the user's call.
            ->assertSeeHtml('data-test="export-copy"');
    });

    it('records the chosen mode in the audit event', function () {
        $task = Task::factory()->for($this->project)->create();
        attachInlineImage($task);

        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $task->task_number])
            ->call('startExport')
            ->set('exportImages', ExportImageMode::Link->value)
            ->call('copyExport');

        $event = json_decode((string) DB::table('audit_outbox')->orderByDesc('id')->value('event'), true);

        expect($event['metadata']['images'])->toBe('link');
    });
});
