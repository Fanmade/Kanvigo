<?php

use App\Models\Attachment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('attachments.disk', 'attachments');
    Storage::fake('attachments');

    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC', 'description' => '']);
});

/**
 * Create an inline attachment owned by the project, backed by a real stored file.
 */
function inlineImage(Project $project): Attachment
{
    Storage::disk('attachments')->put($path = 'attachments/'.fake()->uuid().'.png', 'data');

    return Attachment::factory()->inline()->create([
        'attachable_id' => $project->id,
        'attachable_type' => $project->getMorphClass(),
        'disk' => 'attachments',
        'path' => $path,
    ]);
}

/**
 * The rich-text fragment that embeds an inline image (an <img> pointing at the
 * scoped thumbnail route).
 */
function inlineRef(Attachment $attachment): string
{
    return '<p><img src="/ABC/attachments/'.$attachment->id.'/thumbnail" alt="diagram"></p>';
}

it('deletes an inline attachment (and its file) removed from a description', function () {
    $image = inlineImage($this->project);

    $this->project->update(['description' => inlineRef($image)]);
    $this->project->update(['description' => '<p>Gone.</p>']);

    expect(Attachment::find($image->id))->toBeNull();
    Storage::disk('attachments')->assertMissing($image->path);
});

it('keeps an inline attachment still present after a description edit', function () {
    $image = inlineImage($this->project);

    $this->project->update(['description' => '<p>Intro</p>'.inlineRef($image)]);
    $this->project->update(['description' => '<p>Changed</p>'.inlineRef($image)]);

    expect(Attachment::find($image->id))->not->toBeNull();
});

it('keeps an image removed from the description but still referenced by a comment', function () {
    $image = inlineImage($this->project);
    $this->project->comments()->create(['user_id' => $this->user->id, 'body' => inlineRef($image)]);

    $this->project->update(['description' => inlineRef($image)]);
    $this->project->update(['description' => '']);

    expect(Attachment::find($image->id))->not->toBeNull();
});

it('leaves a freshly-uploaded, unreferenced image alone when another is removed', function () {
    $referenced = inlineImage($this->project);
    $fresh = inlineImage($this->project); // never embedded anywhere

    $this->project->update(['description' => inlineRef($referenced)]);
    $this->project->update(['description' => '']);

    expect(Attachment::find($referenced->id))->toBeNull()
        ->and(Attachment::find($fresh->id))->not->toBeNull();
});

it('swaps the pruned image when a description replaces one image with another', function () {
    $old = inlineImage($this->project);
    $new = inlineImage($this->project);

    $this->project->update(['description' => inlineRef($old)]);
    $this->project->update(['description' => inlineRef($new)]);

    expect(Attachment::find($old->id))->toBeNull()
        ->and(Attachment::find($new->id))->not->toBeNull();
});

it('prunes an inline image when its comment is deleted', function () {
    $image = inlineImage($this->project);
    $comment = $this->project->comments()->create(['user_id' => $this->user->id, 'body' => inlineRef($image)]);

    $comment->delete();

    expect(Attachment::find($image->id))->toBeNull();
});

it('prunes an inline image when its comment is tombstoned', function () {
    $image = inlineImage($this->project);
    $parent = $this->project->comments()->create(['user_id' => $this->user->id, 'body' => inlineRef($image)]);
    $this->project->comments()->create(['user_id' => $this->user->id, 'body' => 'a reply', 'parent_id' => $parent->id]);

    // Tombstone (as CommentList does when a comment has replies): wipe the body.
    $parent->forceFill(['is_deleted' => true, 'body' => ''])->save();

    expect(Attachment::find($image->id))->toBeNull();
});

it('never touches non-inline attachments when a description changes', function () {
    $file = Attachment::factory()->create([
        'attachable_id' => $this->project->id,
        'attachable_type' => $this->project->getMorphClass(),
        'is_inline' => false,
    ]);

    $this->project->update(['description' => '<p>Whatever</p>']);

    expect(Attachment::find($file->id))->not->toBeNull();
});
