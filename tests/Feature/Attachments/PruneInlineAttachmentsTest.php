<?php

use App\Models\Attachment;
use App\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('attachments.disk', 'attachments');
    Storage::fake('attachments');

    $this->project = Project::factory()->create(['description' => '']);
});

/**
 * Create an inline attachment with a real stored file, optionally aged.
 */
function inlineAttachment(Project $project, ?CarbonInterface $createdAt = null): Attachment
{
    Storage::disk('attachments')->put($path = 'attachments/'.fake()->uuid().'.png', 'data');

    return Attachment::factory()->create([
        'attachable_id' => $project->id,
        'attachable_type' => $project->getMorphClass(),
        'disk' => 'attachments',
        'path' => $path,
        'is_inline' => true,
        'created_at' => $createdAt ?? now()->subDays(2),
    ]);
}

it('deletes an aged, unreferenced inline attachment and its file', function () {
    $attachment = inlineAttachment($this->project);

    $this->artisan('attachments:prune-inline')->assertSuccessful();

    expect(Attachment::find($attachment->id))->toBeNull();
    Storage::disk('attachments')->assertMissing($attachment->path);
});

it('keeps inline attachments still referenced in the description', function () {
    $attachment = inlineAttachment($this->project);

    $this->project->update([
        'description' => 'Diagram: '.$attachment->downloadUrl(),
    ]);

    $this->artisan('attachments:prune-inline')->assertSuccessful();

    expect(Attachment::find($attachment->id))->not->toBeNull();
});

it('keeps recent inline attachments within the grace period', function () {
    $attachment = inlineAttachment($this->project, now()->subHour());

    $this->artisan('attachments:prune-inline')->assertSuccessful();

    expect(Attachment::find($attachment->id))->not->toBeNull();
});

it('never touches regular file attachments', function () {
    $file = Attachment::factory()->create([
        'attachable_id' => $this->project->id,
        'attachable_type' => $this->project->getMorphClass(),
        'is_inline' => false,
        'created_at' => now()->subDays(5),
    ]);

    $this->artisan('attachments:prune-inline')->assertSuccessful();

    expect(Attachment::find($file->id))->not->toBeNull();
});
