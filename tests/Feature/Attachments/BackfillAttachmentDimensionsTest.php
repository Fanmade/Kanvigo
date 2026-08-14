<?php

use App\Actions\StoreAttachment;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('attachments.disk', 'attachments');
    Storage::fake('attachments');

    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->user);
    $this->task = Task::factory()->for($this->project)->create();
});

it('records the dimensions of an uploaded image', function () {
    $file = UploadedFile::fake()->createWithContent('scan.png', imageFixture(640, 480));

    $attachment = app(StoreAttachment::class)->handle($file, $this->task, uploadedBy: $this->user->id);

    expect($attachment->width)->toBe(640)
        ->and($attachment->height)->toBe(480);
});

it('leaves dimensions null for a non-image upload', function () {
    $file = UploadedFile::fake()->createWithContent('notes.txt', 'plain text');

    $attachment = app(StoreAttachment::class)->handle($file, $this->task, uploadedBy: $this->user->id);

    expect($attachment->width)->toBeNull()
        ->and($attachment->height)->toBeNull();
});

it('backfills dimensions for rows stored before the columns existed', function () {
    Storage::disk('attachments')->put('attachments/old.png', imageFixture(320, 200));

    $attachment = Attachment::factory()->for($this->task, 'attachable')->create([
        'disk' => 'attachments',
        'path' => 'attachments/old.png',
        'mime_type' => 'image/png',
        'width' => null,
        'height' => null,
    ]);

    $this->artisan('attachments:backfill-dimensions')->assertSuccessful();

    expect($attachment->refresh()->width)->toBe(320)
        ->and($attachment->height)->toBe(200);
});

it('leaves undecodable rows null and still succeeds', function () {
    Storage::disk('attachments')->put('attachments/broken.png', 'not-an-image');

    $attachment = Attachment::factory()->for($this->task, 'attachable')->create([
        'disk' => 'attachments',
        'path' => 'attachments/broken.png',
        'mime_type' => 'image/png',
        'width' => null,
        'height' => null,
    ]);

    $this->artisan('attachments:backfill-dimensions')->assertSuccessful();

    expect($attachment->refresh()->width)->toBeNull();
});
