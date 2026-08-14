<?php

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('attachments.disk', 'attachments');
    Storage::fake('attachments');

    $this->member = User::factory()->create();
    $this->project = Project::factory()->withMembers([$this->member])->create(['short_name' => 'ABC']);
    $this->task = Task::factory()->for($this->project)->create();

    Storage::disk('attachments')->put('attachments/scan.png', imageFixture(2000, 1500));

    $this->attachment = Attachment::factory()->for($this->task, 'attachable')->create([
        'disk' => 'attachments',
        'path' => 'attachments/scan.png',
        'name' => 'scan.png',
        'mime_type' => 'image/png',
        'width' => 2000,
        'height' => 1500,
    ]);
});

it('accepts transform params appended to an already-issued signed link', function () {
    // The link is minted without params — exactly as the MCP tool hands it out.
    $url = $this->attachment->signedDownloadUrl($this->member);

    $response = $this->get($url.'&width=300');

    $response->assertOk();
    expect(getimagesizefromstring($response->getContent())[0])->toBe(300);
});

it('still rejects a link whose attachment was swapped', function () {
    $other = Attachment::factory()->for($this->task, 'attachable')->create();
    $url = $this->attachment->signedDownloadUrl($this->member);

    $this->get(str_replace("/{$this->attachment->id}/", "/{$other->id}/", $url))->assertForbidden();
});
