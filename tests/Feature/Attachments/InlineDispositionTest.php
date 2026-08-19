<?php

use App\Models\Attachment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * How the inline "view" route disposes of each stored type. Uploaded bytes are
 * attacker-controlled content served from the application's own origin, so
 * anything the browser would render as a scriptable document must arrive as a
 * download instead (KAN-568).
 */
beforeEach(function () {
    config()->set('attachments.disk', 'attachments');
    Storage::fake('attachments');

    $this->member = User::factory()->create();
    $this->project = Project::factory()->create();
    joinProject($this->project, $this->member);
});

/**
 * Store a file of the given type on the project and return its attachment.
 */
function projectAttachment(string $path, string $mimeType, string $contents = 'bytes'): Attachment
{
    Storage::disk('attachments')->put('attachments/'.$path, $contents);

    return Attachment::factory()->create([
        'attachable_id' => test()->project->id,
        'attachable_type' => test()->project->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/'.$path,
        'name' => $path,
        'mime_type' => $mimeType,
    ]);
}

it('serves an uploaded SVG as a download, never inline', function () {
    // The attack it prevents: an SVG is a document to the parser, so a <script>
    // inside it runs with the session of whoever opens the view URL.
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>';
    $attachment = projectAttachment('diagram.svg', 'image/svg+xml', $svg);

    $response = $this->actingAs($this->member)->get($attachment->viewUrl());

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toStartWith('attachment');
});

it('keeps serving inline the types that cannot script', function (string $path, string $mimeType) {
    $attachment = projectAttachment($path, $mimeType);

    $response = $this->actingAs($this->member)->get($attachment->viewUrl());

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toStartWith('inline');
})->with([
    ['photo.png', 'image/png'],
    ['scan.jpg', 'image/jpeg'],
    ['spec.pdf', 'application/pdf'],
    ['error.log', 'text/plain'],
    ['clip.mp4', 'video/mp4'],
]);

it('serves scriptable document types as downloads', function (string $path, string $mimeType) {
    $attachment = projectAttachment($path, $mimeType);

    $response = $this->actingAs($this->member)->get($attachment->viewUrl());

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toStartWith('attachment');
})->with([
    ['page.html', 'text/html'],
    ['page.xhtml', 'application/xhtml+xml'],
    ['feed.xml', 'text/xml'],
    ['diagram.svg', 'image/svg+xml'],
]);

it('hardens every attachment response against execution', function () {
    $attachment = projectAttachment('photo.png', 'image/png');
    $attachment->forceFill(['thumbnail_path' => 'attachments/photo.png'])->save();

    foreach ([$attachment->viewUrl(), $attachment->downloadUrl(), $attachment->thumbnailUrl()] as $url) {
        $response = $this->actingAs($this->member)->get($url);

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    }
});

it('does not offer an SVG in the image gallery', function () {
    // isImage() drives the gallery preview, which points at the view URL: a type
    // served as a download would only ever render as a broken image there.
    expect(projectAttachment('diagram.svg', 'image/svg+xml')->isImage())->toBeFalse()
        ->and(projectAttachment('photo.png', 'image/png')->isImage())->toBeTrue();
});
