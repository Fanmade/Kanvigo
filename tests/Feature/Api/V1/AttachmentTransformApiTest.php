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

it('reports transformable true for a legacy image with no stored width', function () {
    // Every image uploaded before the width/height columns existed — or before
    // attachments:backfill-dimensions has run on it — has width === null. Basing
    // `transformable` on that column being set would report these as false even
    // though the driver can decode and re-encode them fine; it has to be
    // MIME-type-plus-encoder-support instead.
    Storage::disk('attachments')->put('attachments/legacy.png', $this->image);

    $legacy = Attachment::factory()->for($this->task, 'attachable')->create([
        'disk' => 'attachments',
        'path' => 'attachments/legacy.png',
        'name' => 'legacy.png',
        'mime_type' => 'image/png',
        'width' => null,
        'height' => null,
    ]);

    Sanctum::actingAs($this->user, ['read']);

    $this->getJson("/api/v1/attachments/{$legacy->id}/metadata")
        ->assertOk()
        ->assertJsonPath('data.width', null)
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

it('serves the stored bytes untouched when no transform param is given', function () {
    Sanctum::actingAs($this->user, ['read']);

    $response = $this->get("/api/v1/attachments/{$this->attachment->id}");

    expect($response->streamedContent())->toBe($this->image);
});

it('serves a downscaled rendition when width is given', function () {
    Sanctum::actingAs($this->user, ['read']);

    $response = $this->get("/api/v1/attachments/{$this->attachment->id}?width=400");
    $response->assertOk()->assertHeader('content-type', 'image/webp');

    expect(getimagesizefromstring($response->getContent())[0])->toBe(400);
});

it('bounds a tall image on height', function () {
    Storage::disk('attachments')->put('attachments/tall.png', imageFixture(200, 1600));

    $tall = Attachment::factory()->for($this->task, 'attachable')->create([
        'disk' => 'attachments',
        'path' => 'attachments/tall.png',
        'name' => 'tall.png',
        'mime_type' => 'image/png',
        'width' => 200,
        'height' => 1600,
    ]);

    Sanctum::actingAs($this->user, ['read']);

    $response = $this->get("/api/v1/attachments/{$tall->id}?height=400");
    $size = getimagesizefromstring($response->getContent());

    expect($size[1])->toBe(400)->and($size[0])->toBe(50);
});

it('honours the requested format and names the file accordingly', function () {
    Sanctum::actingAs($this->user, ['read']);

    $response = $this->get("/api/v1/attachments/{$this->attachment->id}?width=200&format=jpeg");

    $response->assertOk()->assertHeader('content-type', 'image/jpeg');
    expect($response->headers->get('content-disposition'))->toContain('scan.jpg')
        ->and(getimagesizefromstring($response->getContent())[2])->toBe(IMAGETYPE_JPEG);
});

it('rejects an out-of-range dimension', function () {
    Sanctum::actingAs($this->user, ['read']);

    $this->getJson("/api/v1/attachments/{$this->attachment->id}?width=99999")
        ->assertStatus(422)
        ->assertJsonValidationErrors('width');
});

it('rejects an unknown format', function () {
    Sanctum::actingAs($this->user, ['read']);

    $this->getJson("/api/v1/attachments/{$this->attachment->id}?format=bmp")
        ->assertStatus(422)
        ->assertJsonValidationErrors('format');
});

it('rejects transform params on a non-image attachment, even a real PDF Imagick can rasterize', function () {
    // A real PDF, not an undecodable string literal: Imagick happily decodes
    // and rasterizes PDFs via its Ghostscript delegate, so a transform() call
    // on these bytes would succeed and return page 1 as an image with a 200 —
    // this test only proves anything if the MIME-type guard, not decodability,
    // is what produces the 422.
    Storage::disk('attachments')->put('attachments/spec.pdf', pdfFixture());

    $pdf = Attachment::factory()->for($this->task, 'attachable')->create([
        'disk' => 'attachments',
        'path' => 'attachments/spec.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Sanctum::actingAs($this->user, ['read']);

    $response = $this->getJson("/api/v1/attachments/{$pdf->id}?width=200");

    $response->assertStatus(422);
    expect($response->headers->get('content-type'))->not->toContain('image');
});

it('rejects transform params on undecodable bytes claiming to be an image', function () {
    Storage::disk('attachments')->put('attachments/bad.png', 'not actually a png');

    $bad = Attachment::factory()->for($this->task, 'attachable')->create([
        'disk' => 'attachments',
        'path' => 'attachments/bad.png',
        'mime_type' => 'image/png',
    ]);

    Sanctum::actingAs($this->user, ['read']);

    $this->getJson("/api/v1/attachments/{$bad->id}?width=200")->assertStatus(422);
});
