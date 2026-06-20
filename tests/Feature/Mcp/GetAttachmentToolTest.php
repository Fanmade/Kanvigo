<?php

use App\Mcp\Servers\KanbrioServer;
use App\Mcp\Tools\GetAttachmentTool;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Story;
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
    $this->story = Story::factory()->for($this->project)->create();
    $this->task = Task::factory()->for($this->story)->create();
});

it('returns the image content of an inline attachment to a member', function () {
    Storage::disk('attachments')->put('attachments/diagram.png', 'png-bytes');

    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->task->id,
        'attachable_type' => $this->task->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/diagram.png',
        'mime_type' => 'image/png',
        'is_inline' => true,
    ]);

    KanbrioServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk()
        ->assertSee(base64_encode('png-bytes'));
});

it('returns metadata text for a non-viewable attachment type', function () {
    Storage::disk('attachments')->put('attachments/spec.pdf', 'pdf-bytes');

    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->task->id,
        'attachable_type' => $this->task->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/spec.pdf',
        'name' => 'spec.pdf',
        'mime_type' => 'application/pdf',
    ]);

    KanbrioServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk()
        ->assertSee('spec.pdf')
        ->assertSee('cannot be displayed inline');
});

it('denies access to an attachment in a project the user is not a member of', function () {
    $project = Project::factory()->create(['short_name' => 'XYZ']);
    $story = Story::factory()->for($project)->create();
    $task = Task::factory()->for($story)->create();

    $attachment = Attachment::factory()->create([
        'attachable_id' => $task->id,
        'attachable_type' => $task->getMorphClass(),
    ]);

    KanbrioServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertHasErrors();
});

it('errors when the attachment does not exist', function () {
    KanbrioServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => 999999])
        ->assertHasErrors();
});

it('errors when the underlying file is missing from disk', function () {
    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->task->id,
        'attachable_type' => $this->task->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/gone.png',
        'mime_type' => 'image/png',
    ]);

    KanbrioServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertHasErrors();
});

it('errors when the id argument is missing', function () {
    KanbrioServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, [])
        ->assertHasErrors();
});
