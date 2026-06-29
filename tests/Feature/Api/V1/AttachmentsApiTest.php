<?php

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseMissing;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->user);
    $this->task = Task::factory()->for($this->project)->create();
});

it('lists a task downloadable attachments, excluding inline', function () {
    Attachment::factory()->for($this->task, 'attachable')->create(['name' => 'doc.pdf']);
    Attachment::factory()->for($this->task, 'attachable')->inline()->create();

    Sanctum::actingAs($this->user, ['read']);

    $this->getJson("/api/v1/tasks/{$this->task->reference}/attachments")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'doc.pdf')
        ->assertJsonStructure(['data' => [['id', 'name', 'mime_type', 'size', 'is_inline', 'download_url']]]);
});

it('paginates the attachment listing so a large set is bounded', function () {
    Attachment::factory()->for($this->task, 'attachable')->count(20)->create();

    Sanctum::actingAs($this->user, ['read']);

    $response = $this->getJson("/api/v1/tasks/{$this->task->reference}/attachments")
        ->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);

    // The full set is reported in meta, but a single page is capped well below it.
    expect($response->json('meta.total'))->toBe(20)
        ->and(count($response->json('data')))->toBeLessThan(20);
});

it('uploads a file to a task with a write token', function () {
    Storage::fake(config('attachments.disk'));
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->post(
        "/api/v1/tasks/{$this->task->reference}/attachments",
        ['file' => UploadedFile::fake()->create('spec.pdf', 64, 'application/pdf')],
        ['Accept' => 'application/json'],
    )
        ->assertCreated()
        ->assertJsonPath('data.name', 'spec.pdf');

    expect($this->task->attachments()->where('name', 'spec.pdf')->exists())->toBeTrue();
});

it('forbids uploading with a read-only token', function () {
    Storage::fake(config('attachments.disk'));
    Sanctum::actingAs($this->user, ['read']);

    $this->post(
        "/api/v1/tasks/{$this->task->reference}/attachments",
        ['file' => UploadedFile::fake()->create('x.pdf', 1)],
        ['Accept' => 'application/json'],
    )->assertForbidden();
});

it('downloads an uploaded attachment', function () {
    Storage::fake(config('attachments.disk'));
    Sanctum::actingAs($this->user, ['read', 'write']);

    $id = $this->post(
        "/api/v1/tasks/{$this->task->reference}/attachments",
        ['file' => UploadedFile::fake()->create('spec.pdf', 16, 'application/pdf')],
        ['Accept' => 'application/json'],
    )->json('data.id');

    $this->get("/api/v1/attachments/{$id}")->assertOk();
});

it('deletes an attachment with a write token', function () {
    Storage::fake(config('attachments.disk'));
    $attachment = Attachment::factory()->for($this->task, 'attachable')->create();

    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->deleteJson("/api/v1/attachments/{$attachment->id}")->assertNoContent();
    assertDatabaseMissing('attachments', ['id' => $attachment->id]);
});

it('404s attachments for a task the user cannot access', function () {
    $other = Project::factory()->create(['short_name' => 'XYZ']);
    $task = Task::factory()->for($other)->create();
    Sanctum::actingAs($this->user, ['read']);

    $this->getJson("/api/v1/tasks/{$task->reference}/attachments")->assertNotFound();
});
