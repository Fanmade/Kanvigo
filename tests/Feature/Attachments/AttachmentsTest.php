<?php

use App\Livewire\Projects\ProjectShow;
use App\Livewire\Stories\StoryView;
use App\Livewire\Tasks\TaskView;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('attachments.disk', 'attachments');
    Storage::fake('attachments');

    $this->member = User::factory()->create();
    $this->project = Project::factory()->create();
    $this->project->members()->attach($this->member);
});

it('uploads dropped files onto a project and stores them on the configured disk', function () {
    $file = UploadedFile::fake()->create('spec.pdf', 200, 'application/pdf');

    Livewire::actingAs($this->member)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->set('newFiles', [$file])
        ->assertHasNoErrors();

    $attachment = $this->project->attachments()->first();

    expect($attachment)->not->toBeNull()
        ->and($attachment->name)->toBe('spec.pdf')
        ->and($attachment->disk)->toBe('attachments')
        ->and($attachment->mime_type)->toBe('application/pdf')
        ->and($attachment->size)->toBeGreaterThan(0)
        ->and($attachment->uploaded_by)->toBe($this->member->id);

    Storage::disk('attachments')->assertExists($attachment->path);
});

it('generates a thumbnail for image uploads', function () {
    Livewire::actingAs($this->member)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->set('newFiles', [UploadedFile::fake()->image('photo.png', 600, 400)])
        ->assertHasNoErrors();

    $attachment = $this->project->attachments()->first();

    expect($attachment->hasThumbnail())->toBeTrue();
    Storage::disk('attachments')->assertExists($attachment->thumbnail_path);
});

it('embeds a pasted image into the description as linked markdown', function () {
    $component = Livewire::actingAs($this->member)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->set('description', 'Hello world')
        ->set('inlineImage', UploadedFile::fake()->image('diagram.png', 400, 300))
        ->call('addInlineImage', 5)
        ->assertHasNoErrors();

    $attachment = $this->project->attachments()->where('is_inline', true)->first();

    expect($attachment)->not->toBeNull()
        ->and($attachment->is_inline)->toBeTrue()
        ->and($attachment->hasThumbnail())->toBeTrue();

    $description = $component->get('description');

    expect($description)
        ->toContain('[![diagram.png]')
        ->toContain($attachment->thumbnailUrl(absolute: false))
        ->toContain($attachment->viewUrl(absolute: false))
        // URLs are project-scoped and relative, not absolute.
        ->toContain('/'.$this->project->short_name.'/attachments/')
        ->not->toContain($attachment->viewUrl())
        ->and(str_starts_with($description, 'Hello[!['))->toBeTrue()
        ->and(str_ends_with($description, ' world'))->toBeTrue();

    // The temporary upload is cleared once embedded.
    $component->assertSet('inlineImage', null);
});

it('keeps inline images out of the attachment tile list', function () {
    Attachment::factory()->create([
        'attachable_id' => $this->project->id,
        'attachable_type' => $this->project->getMorphClass(),
        'name' => 'standalone.pdf',
        'is_inline' => false,
    ]);
    Attachment::factory()->create([
        'attachable_id' => $this->project->id,
        'attachable_type' => $this->project->getMorphClass(),
        'name' => 'embedded.png',
        'is_inline' => true,
    ]);

    $tiles = Livewire::actingAs($this->member)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->instance()
        ->attachments();

    expect($tiles)->toHaveCount(1)
        ->and($tiles->first()->name)->toBe('standalone.pdf');
});

it('does not generate a thumbnail for unsupported uploads', function () {
    Livewire::actingAs($this->member)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->set('newFiles', [UploadedFile::fake()->create('notes.txt', 10, 'text/plain')])
        ->assertHasNoErrors();

    expect($this->project->attachments()->first()->thumbnail_path)->toBeNull();
});

it('generates a thumbnail for PDF uploads', function () {
    $pdf = new Imagick;
    $pdf->newImage(800, 1000, new ImagickPixel('skyblue'));
    $pdf->setImageFormat('pdf');
    $bytes = $pdf->getImageBlob();
    $pdf->clear();

    $file = UploadedFile::fake()->createWithContent('report.pdf', $bytes);

    Livewire::actingAs($this->member)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->set('newFiles', [$file])
        ->assertHasNoErrors();

    $attachment = $this->project->attachments()->first();

    expect($attachment->hasThumbnail())->toBeTrue();
    Storage::disk('attachments')->assertExists($attachment->thumbnail_path);
});

it('serves an image thumbnail inline to a member', function () {
    Storage::disk('attachments')->put('attachments/thumbnails/thumb.png', 'png-bytes');

    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->project->id,
        'attachable_type' => $this->project->getMorphClass(),
        'disk' => 'attachments',
        'thumbnail_path' => 'attachments/thumbnails/thumb.png',
    ]);

    $this->actingAs($this->member)
        ->get($attachment->thumbnailUrl())
        ->assertOk();
});

it('returns 404 for the thumbnail of an attachment without one', function () {
    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->project->id,
        'attachable_type' => $this->project->getMorphClass(),
        'thumbnail_path' => null,
    ]);

    $this->actingAs($this->member)
        ->get($attachment->thumbnailUrl())
        ->assertNotFound();
});

it('returns 404 when the project short name does not match the attachment', function () {
    $other = Project::factory()->create();
    $other->members()->attach($this->member);

    Storage::disk('attachments')->put('attachments/report.pdf', 'data');

    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->project->id,
        'attachable_type' => $this->project->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/report.pdf',
    ]);

    $this->actingAs($this->member)
        ->get("/{$other->short_name}/attachments/{$attachment->id}/download")
        ->assertNotFound();
});

it('forbids thumbnail access from non-members', function () {
    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->project->id,
        'attachable_type' => $this->project->getMorphClass(),
        'thumbnail_path' => 'attachments/thumbnails/thumb.png',
    ]);

    $this->actingAs(User::factory()->create())
        ->get($attachment->thumbnailUrl())
        ->assertForbidden();
});

it('uploads files onto a story', function () {
    $story = Story::factory()->for($this->project)->create();

    Livewire::actingAs($this->member)
        ->test(StoryView::class, [
            'short_name' => $this->project->short_name,
            'story_number' => $story->story_number,
        ])
        ->set('newFiles', [UploadedFile::fake()->image('shot.png')])
        ->assertHasNoErrors();

    expect($story->attachments()->count())->toBe(1);
});

it('uploads files onto a task', function () {
    $story = Story::factory()->for($this->project)->create();
    $task = Task::factory()->for($story)->create();

    Livewire::actingAs($this->member)
        ->test(TaskView::class, [
            'short_name' => $this->project->short_name,
            'task_number' => $task->task_number,
        ])
        ->set('newFiles', [UploadedFile::fake()->create('notes.txt', 5)])
        ->assertHasNoErrors();

    expect($task->attachments()->count())->toBe(1);
});

it('records an activity when a file is attached', function () {
    Livewire::actingAs($this->member)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->set('newFiles', [UploadedFile::fake()->create('doc.pdf', 10)]);

    expect($this->project->activities()->where('action', 'attachment_added')->exists())->toBeTrue();
});

it('rejects files larger than the configured maximum', function () {
    config()->set('attachments.max_size', 100);

    Livewire::actingAs($this->member)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->set('newFiles', [UploadedFile::fake()->create('big.pdf', 500)])
        ->assertHasErrors('newFiles.*');

    expect($this->project->attachments()->count())->toBe(0);
});

it('forbids uploads from non-members', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->assertForbidden();
});

it('deletes an attachment and removes the underlying file and thumbnail', function () {
    Storage::disk('attachments')->put('attachments/old.pdf', 'data');
    Storage::disk('attachments')->put('attachments/thumbnails/old.png', 'thumb');

    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->project->id,
        'attachable_type' => $this->project->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/old.pdf',
        'thumbnail_path' => 'attachments/thumbnails/old.png',
    ]);

    Livewire::actingAs($this->member)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->call('deleteAttachment', $attachment->id)
        ->assertHasNoErrors();

    expect(Attachment::find($attachment->id))->toBeNull();
    Storage::disk('attachments')->assertMissing('attachments/old.pdf');
    Storage::disk('attachments')->assertMissing('attachments/thumbnails/old.png');
});

it('only lets members delete an attachment', function () {
    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->project->id,
        'attachable_type' => $this->project->getMorphClass(),
    ]);

    $outsider = User::factory()->create();

    expect($this->member->can('delete', $attachment))->toBeTrue()
        ->and($outsider->can('delete', $attachment))->toBeFalse();
});

it('lets a member download an attachment', function () {
    Storage::disk('attachments')->put('attachments/report.pdf', 'data');

    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->project->id,
        'attachable_type' => $this->project->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/report.pdf',
        'name' => 'report.pdf',
    ]);

    $this->actingAs($this->member)
        ->get($attachment->downloadUrl())
        ->assertOk()
        ->assertDownload('report.pdf');
});

it('forbids downloads from non-members', function () {
    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->project->id,
        'attachable_type' => $this->project->getMorphClass(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get($attachment->downloadUrl())
        ->assertForbidden();
});
