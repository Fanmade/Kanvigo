<?php

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('attachments.disk', 'attachments');
    Storage::fake('attachments');

    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->user);
    $this->task = Task::factory()->for($this->project)->create();

    $this->image = imageFixture(2000, 1500);
    Storage::disk('attachments')->put('attachments/scan.png', $this->image);

    $this->attachment = Attachment::factory()->for($this->task, 'attachable')->create([
        'disk' => 'attachments',
        'path' => 'attachments/scan.png',
        'name' => 'scan.png',
        'mime_type' => 'image/png',
        'size' => strlen($this->image),
        'width' => 2000,
        'height' => 1500,
    ]);
});

it('returns full metadata for an image attachment', function () {
    Sanctum::actingAs($this->user, ['read']);

    $this->getJson("/api/v1/attachments/{$this->attachment->id}/metadata")
        ->assertOk()
        ->assertJsonPath('data.name', 'scan.png')
        ->assertJsonPath('data.mime_type', 'image/png')
        ->assertJsonPath('data.width', 2000)
        ->assertJsonPath('data.height', 1500)
        ->assertJsonPath('data.transformable', true);
});

it('reports a non-image attachment as not transformable', function () {
    Storage::disk('attachments')->put('attachments/notes.txt', 'plain text');

    $text = Attachment::factory()->for($this->task, 'attachable')->create([
        'disk' => 'attachments',
        'path' => 'attachments/notes.txt',
        'mime_type' => 'text/plain',
    ]);

    Sanctum::actingAs($this->user, ['read']);

    $this->getJson("/api/v1/attachments/{$text->id}/metadata")
        ->assertOk()
        ->assertJsonPath('data.transformable', false)
        ->assertJsonPath('data.width', null);
});

it('404s on metadata for an attachment outside the caller projects', function () {
    $outsider = User::factory()->create();
    Sanctum::actingAs($outsider, ['read']);

    $this->getJson("/api/v1/attachments/{$this->attachment->id}/metadata")->assertNotFound();
});
