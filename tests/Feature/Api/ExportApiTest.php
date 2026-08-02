<?php

use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Export\ExportOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(config('attachments.disk'));

    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->member = userWithRole($this->project, 'member');
    $this->viewer = userWithRole($this->project, 'viewer');

    $this->task = Task::factory()->for($this->project)->create([
        'title' => 'Export functionality',
        'description' => '<p>Ship the <strong>MVP</strong> first.</p>',
    ]);
});

/** The export endpoint for the task under test. */
function exportUrl(array $query = []): string
{
    return route('api.v1.tasks.export', ['reference' => test()->task->reference, ...$query]);
}

describe('exporting a task', function () {
    it('returns Markdown with the same defaults the dialog has', function () {
        Sanctum::actingAs($this->member);

        $response = $this->get(exportUrl());

        $response->assertOk()
            ->assertHeader('content-type', 'text/markdown; charset=UTF-8');

        expect($response->getContent())
            // Metadata on by default, images left as links to this instance.
            ->toStartWith("---\n")
            ->toContain('reference: '.$this->task->reference)
            ->toContain('# Export functionality')
            ->toContain('Ship the **MVP** first.');
    });

    it('names the file in the content disposition', function () {
        Sanctum::actingAs($this->member);

        $this->get(exportUrl())->assertHeader(
            'content-disposition',
            'attachment; filename="'.strtolower($this->task->reference).'-export-functionality.md"',
        );
    });

    it('honours the options as query parameters', function () {
        Sanctum::actingAs($this->member);
        Task::factory()->for($this->project)->childOf($this->task)->create(['title' => 'The MVP']);

        $content = $this->get(exportUrl(['metadata' => 0, 'descendants' => 1]))->getContent();

        expect($content)->toStartWith('# Export functionality')
            ->toContain('## The MVP');
    });

    it('renders an HTML page when asked for one', function () {
        Sanctum::actingAs($this->member);

        $response = $this->get(exportUrl(['format' => 'html']));

        $response->assertOk()->assertHeader('content-type', 'text/html; charset=UTF-8');

        expect($response->getContent())->toStartWith('<!DOCTYPE html>');
    });

    it('returns a ZIP when the options need files to travel', function () {
        Sanctum::actingAs($this->member);
        attachInlineImage($this->task);

        $response = $this->get(exportUrl(['images' => 'files']));

        $response->assertOk()
            ->assertHeader('content-type', 'application/zip')
            ->assertHeader('content-disposition', 'attachment; filename="'.strtolower($this->task->reference).'-export-functionality.zip"');

        expect(substr($response->getContent(), 0, 2))->toBe('PK');
    });

    it('ignores the caller\'s remembered preferences', function () {
        // The web dialog remembers metadata off; the API must not inherit that.
        $this->member->setPreference(ExportOptions::PREFERENCE_KEY, ['metadata' => false]);

        Sanctum::actingAs($this->member);

        expect($this->get(exportUrl())->getContent())->toStartWith("---\n");
    });

    it('rejects an option it does not understand', function () {
        Sanctum::actingAs($this->member);

        $this->getJson(exportUrl(['format' => 'papyrus']))->assertStatus(422);
    });
});

describe('exporting a doc', function () {
    it('exports a published doc', function () {
        Sanctum::actingAs($this->member);
        $doc = Doc::factory()->for($this->project)->create(['title' => 'Style guide', 'is_public' => true]);

        $response = $this->get(route('api.v1.docs.export', ['reference' => $doc->reference]));

        $response->assertOk();
        expect($response->getContent())->toContain('# Style guide');
    });

    it('404s a draft for someone who may not edit docs', function () {
        Sanctum::actingAs($this->viewer);
        $draft = Doc::factory()->for($this->project)->create(['is_public' => false]);

        $this->getJson(route('api.v1.docs.export', ['reference' => $draft->reference]))->assertNotFound();
    });
});

describe('authorization', function () {
    it('refuses a viewer, who may read but not export', function () {
        Sanctum::actingAs($this->viewer);

        $this->getJson(exportUrl())->assertForbidden();
    });

    it('404s a reference in a project the caller is not in', function () {
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);

        $this->getJson(exportUrl())->assertNotFound();
    });

    it('404s a reference that does not exist', function () {
        Sanctum::actingAs($this->member);

        $this->getJson(route('api.v1.tasks.export', ['reference' => 'ABC-9999']))->assertNotFound();
    });

    it('needs a token at all', function () {
        $this->getJson(exportUrl())->assertUnauthorized();
    });
});

describe('the audit trail', function () {
    it('records the same content_exported event the dialog does, from the API', function () {
        Sanctum::actingAs($this->member);

        $this->get(exportUrl(['format' => 'html']));

        $event = json_decode((string) DB::table('audit_outbox')->orderByDesc('id')->value('event'), true);

        expect($event['action'])->toBe('content_exported')
            ->and($event['category'])->toBe('access')
            ->and($event['subject_id'])->toBe($this->task->id)
            ->and($event['metadata']['format'])->toBe('html')
            // The API middleware stamps the source, so an auditor can tell a
            // token-driven export from someone clicking Download.
            ->and($event['context']['source'] ?? null)->toBe('api');
    });
});
