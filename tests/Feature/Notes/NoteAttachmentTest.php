<?php

use App\Models\Attachment;
use App\Models\Note;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('attachments.disk', 'attachments');
    Storage::fake('attachments');
});

/**
 * Create an inline image attachment owned by a note, backed by a real file.
 */
function noteInlineAttachment(Note $note): Attachment
{
    Storage::disk('attachments')->put($path = 'attachments/'.fake()->uuid().'.png', 'data');

    return Attachment::factory()->inline()->create([
        'attachable_id' => $note->id,
        'attachable_type' => $note->getMorphClass(),
        'disk' => 'attachments',
        'path' => $path,
    ]);
}

it('serves a note inline attachment to its owner (projectless route)', function () {
    $owner = User::factory()->create();
    $attachment = noteInlineAttachment(Note::factory()->for($owner)->create());

    expect($attachment->viewUrl(absolute: false))->toContain('/notes/attachments/');

    $this->actingAs($owner)
        ->get($attachment->viewUrl(absolute: false))
        ->assertOk();
});

it('denies a private note attachment to a non-owner', function () {
    $attachment = noteInlineAttachment(Note::factory()->create());

    $this->actingAs(User::factory()->create())
        ->get($attachment->viewUrl(absolute: false))
        ->assertForbidden();
});

it('serves a public note attachment to a project member', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, [$owner->id, $member->id]);

    $attachment = noteInlineAttachment(Note::factory()->for($owner)->publicTo($project)->create());

    $this->actingAs($member)
        ->get($attachment->viewUrl(absolute: false))
        ->assertOk();
});

it('denies a public note attachment to a non-member', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $owner);

    $attachment = noteInlineAttachment(Note::factory()->for($owner)->publicTo($project)->create());

    $this->actingAs(User::factory()->create())
        ->get($attachment->viewUrl(absolute: false))
        ->assertForbidden();
});
