<?php

use App\Mcp\Servers\KanvigoServer;
use App\Mcp\Tools\GetAttachmentTool;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * The decoded `attachment_downloaded` access events currently in the outbox.
 *
 * @return Collection<int, array<string, mixed>>
 */
function attachmentDownloadAudits(): Collection
{
    return DB::table('audit_outbox')->where('event->action', 'attachment_downloaded')->orderBy('id')->get()
        ->map(static fn (object $row): array => json_decode((string) $row->event, true, flags: JSON_THROW_ON_ERROR));
}

beforeEach(function () {
    config()->set('attachments.disk', 'attachments');
    Storage::fake('attachments');

    $this->member = User::factory()->create();
    $this->project = Project::factory()->withMembers([$this->member])->create(['short_name' => 'ABC']);
    $this->task = Task::factory()->for($this->project)->create();
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

    KanvigoServer::actingAs($this->member)
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

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk()
        ->assertSee('spec.pdf')
        ->assertSee('cannot be displayed inline');
});

it('returns the contents of a text-based attachment inline', function () {
    Storage::disk('attachments')->put('attachments/error.log', "boom on line 1\nboom on line 2\n");

    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->task->id,
        'attachable_type' => $this->task->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/error.log',
        'name' => 'error.log',
        'mime_type' => 'text/plain',
    ]);

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk()
        ->assertSee('boom on line 1')
        ->assertSee('boom on line 2');
});

it('inlines an allow-listed textual application type (JSON)', function () {
    Storage::disk('attachments')->put('attachments/payload.json', '{"status":"failed"}');

    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->task->id,
        'attachable_type' => $this->task->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/payload.json',
        'name' => 'payload.json',
        'mime_type' => 'application/json',
    ]);

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk()
        ->assertSee('"status":"failed"');
});

it('returns the first page of a large text attachment with a next-offset notice', function () {
    $cap = 256 * 1024;
    $body = str_repeat('a', $cap).'TAIL-MARKER';
    Storage::disk('attachments')->put('attachments/big.log', $body);

    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->task->id,
        'attachable_type' => $this->task->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/big.log',
        'name' => 'big.log',
        'mime_type' => 'text/plain',
    ]);

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk()
        ->assertSee('bytes 0–'.$cap.' of '.($cap + 11))
        ->assertSee('call again with offset='.$cap)
        ->assertDontSee('TAIL-MARKER');
});

it('pages to a later section of a text attachment via offset', function () {
    $cap = 256 * 1024;
    $body = str_repeat('a', $cap).'TAIL-MARKER';
    Storage::disk('attachments')->put('attachments/big.log', $body);

    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->task->id,
        'attachable_type' => $this->task->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/big.log',
        'name' => 'big.log',
        'mime_type' => 'text/plain',
    ]);

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id, 'offset' => $cap])
        ->assertOk()
        ->assertSee('TAIL-MARKER')
        ->assertSee('end of file');
});

it('reports when the offset is past the end of the file', function () {
    Storage::disk('attachments')->put('attachments/small.log', 'short');

    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->task->id,
        'attachable_type' => $this->task->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/small.log',
        'name' => 'small.log',
        'mime_type' => 'text/plain',
    ]);

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id, 'offset' => 9999])
        ->assertOk()
        ->assertSee('past the end of the file');
});

it('falls back to metadata when a text-typed file is not valid UTF-8', function () {
    Storage::disk('attachments')->put('attachments/corrupt.log', "\xff\xfe\x00binary");

    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->task->id,
        'attachable_type' => $this->task->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/corrupt.log',
        'name' => 'corrupt.log',
        'mime_type' => 'text/plain',
    ]);

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk()
        ->assertSee('corrupt.log')
        ->assertSee('cannot be displayed inline');
});

it('denies access to an attachment in a project the user is not a member of', function () {
    $project = Project::factory()->create(['short_name' => 'XYZ']);
    $task = Task::factory()->for($project)->create();

    $attachment = Attachment::factory()->create([
        'attachable_id' => $task->id,
        'attachable_type' => $task->getMorphClass(),
    ]);

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertHasErrors();
});

it('errors when the attachment does not exist', function () {
    KanvigoServer::actingAs($this->member)
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

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertHasErrors();
});

it('errors when the id argument is missing', function () {
    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, [])
        ->assertHasErrors();
});

it('audits serving image content as an attachment download', function () {
    Storage::disk('attachments')->put('attachments/diagram.png', 'png-bytes');

    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->task->id,
        'attachable_type' => $this->task->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/diagram.png',
        'name' => 'diagram.png',
        'mime_type' => 'image/png',
    ]);

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk();

    $event = attachmentDownloadAudits()->sole();
    expect($event['category'])->toBe('access')
        ->and($event['subject_type'])->toBe($attachment->getMorphClass())
        ->and($event['subject_id'])->toBe($attachment->id)
        ->and($event['metadata']['name'])->toBe('diagram.png')
        ->and($event['actor_id'])->toBe($this->member->id);
});

it('audits serving text content as an attachment download', function () {
    Storage::disk('attachments')->put('attachments/error.log', 'boom');

    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->task->id,
        'attachable_type' => $this->task->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/error.log',
        'name' => 'error.log',
        'mime_type' => 'text/plain',
    ]);

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk();

    expect(attachmentDownloadAudits()->sole()['subject_id'])->toBe($attachment->id);
});

it('does not audit the metadata-only fallthrough for a non-viewable type', function () {
    Storage::disk('attachments')->put('attachments/spec.pdf', 'pdf-bytes');

    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->task->id,
        'attachable_type' => $this->task->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/spec.pdf',
        'name' => 'spec.pdf',
        'mime_type' => 'application/pdf',
    ]);

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk()
        ->assertSee('cannot be displayed inline');

    expect(attachmentDownloadAudits())->toBeEmpty();
});
