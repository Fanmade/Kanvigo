<?php

use App\Mcp\Servers\KanvigoServer;
use App\Mcp\Tools\GetAttachmentTool;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Images\Contracts\ImageDriver;
use App\Support\Images\Drivers\GdDriver;
use App\Support\Images\ImageTransformer;
use App\Support\Images\TransformSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Mcp\Transport\JsonRpcResponse;

uses(RefreshDatabase::class);

/**
 * The content blocks of an MCP tool response. `TestResponse` keeps the
 * JSON-RPC response protected and exposes only assertions over its text, so a
 * test that needs the raw blocks has to reach for the property itself.
 *
 * @return array<int, array<string, mixed>>
 */
function mcpResponseContent(TestResponse $response): array
{
    /** @var JsonRpcResponse $jsonRpcResponse */
    $jsonRpcResponse = (new ReflectionProperty($response, 'response'))->getValue($response);

    return $jsonRpcResponse->toArray()['result']['content'] ?? [];
}

/**
 * The decoded bytes of the first image content block in an MCP tool response —
 * the raw output, not the notice text describing it, so a test can assert
 * against what the driver actually produced rather than what was asked for.
 */
function mcpImageBytes(TestResponse $response): string
{
    $image = collect(mcpResponseContent($response))->firstWhere('type', 'image');

    expect($image)->not->toBeNull('The response has no image content block.');

    return base64_decode((string) $image['data'], strict: true);
}

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

it('returns a working signed download URL alongside viewable image content', function () {
    $this->freezeTime();
    Storage::disk('attachments')->put('attachments/photo.jpg', 'jpg-bytes');

    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->task->id,
        'attachable_type' => $this->task->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/photo.jpg',
        'name' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    $url = $attachment->signedDownloadUrl($this->member);

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk()
        ->assertSee(base64_encode('jpg-bytes'))
        ->assertSee($url);

    expect($this->get($url)->assertOk()->streamedContent())->toBe('jpg-bytes');
});

it('offers a signed download URL for a type that cannot be displayed inline', function () {
    $this->freezeTime();
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
        ->assertSee('cannot be displayed inline')
        ->assertSee($attachment->signedDownloadUrl($this->member));
});

it('offers a signed download URL alongside inline text content', function () {
    $this->freezeTime();
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
        ->assertOk()
        ->assertSee('boom')
        ->assertSee($attachment->signedDownloadUrl($this->member));
});

it('issues the signed download URL for the calling user, not another member', function () {
    $this->freezeTime();
    Storage::disk('attachments')->put('attachments/photo.jpg', 'jpg-bytes');

    $other = User::factory()->create();
    $this->project->members()->attach($other);

    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->task->id,
        'attachable_type' => $this->task->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/photo.jpg',
        'name' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk()
        ->assertSee($attachment->signedDownloadUrl($this->member))
        ->assertDontSee($attachment->signedDownloadUrl($other));
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

/**
 * Store an image attachment on the test task and return it.
 */
function imageAttachment(string $bytes, string $path = 'attachments/scan.png'): Attachment
{
    Storage::disk('attachments')->put($path, $bytes);
    $dimensions = app(ImageTransformer::class)->dimensions($bytes);

    return Attachment::factory()->create([
        'attachable_id' => test()->task->id,
        'attachable_type' => test()->task->getMorphClass(),
        'disk' => 'attachments',
        'path' => $path,
        'name' => basename($path),
        'mime_type' => 'image/png',
        'size' => strlen($bytes),
        'width' => $dimensions[0] ?? null,
        'height' => $dimensions[1] ?? null,
    ]);
}

it('downscales a large image instead of inlining megabytes of base64', function () {
    $original = imageFixture(4000, 3000);
    $attachment = imageAttachment($original);

    $response = KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk();

    $response->assertSee('downscaled');
    $response->assertDontSee(base64_encode($original));
});

it('downscales a tall image, which a width-only cap would have missed', function () {
    $attachment = imageAttachment(imageFixture(1000, 8000), 'attachments/tall.png');

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk()
        ->assertSee('downscaled');
});

it('returns a small image untouched', function () {
    $original = imageFixture(200, 150);
    $attachment = imageAttachment($original, 'attachments/small.png');

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk()
        ->assertSee(base64_encode($original));
});

it('honours explicit transform params', function () {
    $attachment = imageAttachment(imageFixture(2000, 1500));

    $response = KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id, 'width' => 300, 'format' => 'jpeg'])
        ->assertOk();

    $info = getimagesizefromstring(mcpImageBytes($response));

    expect($info)->not->toBeFalse()
        ->and($info[2])->toBe(IMAGETYPE_JPEG)
        ->and($info[0])->toBe(300);
});

it('errors rather than silently serving untransformed bytes when the requested format cannot be encoded', function () {
    // Bind a driver that reports every format as unencodable, so the test does
    // not depend on whether this host actually has an AVIF encoder. Without the
    // supportsFormat() guard, transform() returns null, transformFailed is set,
    // and the tool would inline the *original* PNG bytes while the response
    // text still claims webp/avif — the caller believes it got a format it did
    // not get.
    app()->instance(ImageTransformer::class, new ImageTransformer(new class implements ImageDriver
    {
        public function available(): bool
        {
            return true;
        }

        public function supportsFormat(string $format): bool
        {
            return false;
        }

        public function dimensions(string $bytes): ?array
        {
            return (new GdDriver)->dimensions($bytes);
        }

        public function transform(string $bytes, TransformSpec $spec): ?string
        {
            return null;
        }
    }));

    $attachment = imageAttachment(imageFixture(400, 300));

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id, 'format' => 'avif'])
        ->assertHasErrors()
        ->assertSee('avif');
});

it('rejects transform params on a non-image attachment instead of ignoring them', function () {
    Storage::disk('attachments')->put('attachments/song.mp3', 'mp3-bytes');

    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->task->id,
        'attachable_type' => $this->task->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/song.mp3',
        'mime_type' => 'audio/mpeg',
        'size' => strlen('mp3-bytes'),
    ]);

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id, 'width' => 200])
        ->assertHasErrors();
});

it('re-encodes, but does not claim to downscale, an image that is small in pixels but over the byte threshold', function () {
    // Photographic noise compresses poorly, so this is well over 512 KiB while
    // staying inside the 1568px edge-length bound — it can only trip the byte
    // half of the auto-transform check, not the edge-length half. Because the
    // 500x500 source is already inside the 1568x1568 default box, the transform
    // re-encodes it to WebP without resizing anything — the notice must say
    // "re-encoded", not "downscaled to 500×500", or it claims a resize that
    // never happened.
    $original = noisyImageFixture(500, 500);
    expect(strlen($original))->toBeGreaterThan(512 * 1024);

    $attachment = imageAttachment($original, 'attachments/noisy.png');

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk()
        ->assertSee('re-encoded')
        ->assertDontSee('downscaled');
});

it('audits serving a transformed image as an attachment download', function () {
    $attachment = imageAttachment(imageFixture(4000, 3000));

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk()
        ->assertSee('downscaled');

    expect(attachmentDownloadAudits()->sole()['subject_id'])->toBe($attachment->id);
});

it('falls back to metadata for a large image no driver can decode', function () {
    // 3 MB of bytes that are not a decodable image — the HEIC/TIFF case.
    $attachment = imageAttachment(str_repeat('x', 3 * 1024 * 1024), 'attachments/scan.heic');

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk()
        ->assertSee('cannot be displayed inline')
        ->assertSee('signed URL');
});

it('refuses to inline an oversized audio file', function () {
    Storage::disk('attachments')->put('attachments/long.mp3', str_repeat('a', 5 * 1024 * 1024));

    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->task->id,
        'attachable_type' => $this->task->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/long.mp3',
        'name' => 'long.mp3',
        'mime_type' => 'audio/mpeg',
        'size' => 5 * 1024 * 1024,
    ]);

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk()
        ->assertSee('too large to return inline')
        ->assertSee('signed URL');
});

it('does not audit a content read when it only returns metadata', function () {
    $attachment = imageAttachment(str_repeat('x', 3 * 1024 * 1024), 'attachments/scan.heic');

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk();

    expect(attachmentDownloadAudits())->toBeEmpty();
});

it('does not inline an SVG as image content, and refuses transform params on it', function () {
    // "image/*" is not the set of types we hand to a decoder: an SVG is
    // rasterized by ImageMagick's librsvg delegate, so it is treated as an
    // opaque file — metadata plus a signed link, like a PDF.
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="80"></svg>';
    Storage::disk('attachments')->put('attachments/diagram.svg', $svg);

    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->task->id,
        'attachable_type' => $this->task->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/diagram.svg',
        'name' => 'diagram.svg',
        'mime_type' => 'image/svg+xml',
    ]);

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk()
        ->assertDontSee(base64_encode($svg))
        ->assertSee('diagram.svg');

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id, 'width' => 200])
        ->assertHasErrors();
});
