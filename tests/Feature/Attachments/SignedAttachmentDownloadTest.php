<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('attachments.disk', 'attachments');
    Storage::fake('attachments');

    $this->member = User::factory()->create();
    $this->project = Project::factory()->withMembers([$this->member])->create(['short_name' => 'ABC']);
    $this->task = Task::factory()->for($this->project)->create();

    Storage::disk('attachments')->put('attachments/photo.jpg', 'jpg-bytes');

    $this->attachment = Attachment::factory()->create([
        'attachable_id' => $this->task->id,
        'attachable_type' => $this->task->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/photo.jpg',
        'name' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
    ]);
});

it('streams the file to a guest following a signed link issued for a member', function () {
    $response = $this->get($this->attachment->signedDownloadUrl($this->member));

    $response->assertOk()
        ->assertDownload('photo.jpg');

    expect($response->streamedContent())->toBe('jpg-bytes');
});

it('rejects a tampered link', function () {
    $url = $this->attachment->signedDownloadUrl($this->member);

    $this->get($url.'&extra=1')->assertForbidden();
});

it('rejects an expired link', function () {
    $url = $this->attachment->signedDownloadUrl($this->member, 5);

    $this->travelTo(Carbon::now()->addMinutes(6));

    $this->get($url)->assertForbidden();
});

it('404s when the user the link was issued for cannot access the attachment', function () {
    $outsider = User::factory()->create();

    $this->get($this->attachment->signedDownloadUrl($outsider))->assertNotFound();
});

it('404s once the user loses access after the link was issued', function () {
    $url = $this->attachment->signedDownloadUrl($this->member);

    app(ProjectRoleProvisioner::class)->syncMember($this->project, $this->member, null);
    $this->project->members()->detach($this->member);

    $this->get($url)->assertNotFound();
});

it('404s when the file is gone from disk', function () {
    Storage::disk('attachments')->delete('attachments/photo.jpg');

    $this->get($this->attachment->signedDownloadUrl($this->member))->assertNotFound();
});

it('audits the download against the user the link was issued for', function () {
    $this->get($this->attachment->signedDownloadUrl($this->member))->assertOk();

    $row = DB::table('audit_outbox')->where('event->action', 'attachment_downloaded')->sole();
    $event = json_decode((string) $row->event, true, flags: JSON_THROW_ON_ERROR);

    expect($event['actor_id'])->toBe($this->member->id)
        ->and($event['subject_id'])->toBe($this->attachment->id)
        ->and($event['metadata']['name'])->toBe('photo.jpg');
});

it('does not log the link holder in', function () {
    $this->get($this->attachment->signedDownloadUrl($this->member))->assertOk();

    $this->assertGuest();
});
