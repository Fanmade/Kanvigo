<?php

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('attachments.disk', 'attachments');
    Storage::fake('attachments');

    $this->member = User::factory()->create();
    $this->project = Project::factory()->create();
    joinProject($this->project, $this->member);
    $this->task = Task::factory()->for($this->project)->create();
});

/**
 * A valid 1x1 transparent PNG, so the lightbox <img> loads without a console
 * error tripping assertNoJavascriptErrors().
 */
function tinyPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
}

/**
 * An image attachment on the task. Pass $createdAt whenever the test asserts a
 * gallery position: the gallery lists newest first, and unstamped fixtures all
 * land in the same second, so their order would depend on where the clock falls
 * during the run.
 */
function makeImageAttachment(Task $task, string $name, ?CarbonInterface $createdAt = null): Attachment
{
    $path = 'attachments/'.fake()->uuid().'.png';
    Storage::disk('attachments')->put($path, tinyPng());

    return Attachment::factory()->create([
        'attachable_id' => $task->id,
        'attachable_type' => $task->getMorphClass(),
        'disk' => 'attachments',
        'path' => $path,
        'name' => $name,
        'mime_type' => 'image/png',
        'created_at' => $createdAt ?? now(),
        'updated_at' => $createdAt ?? now(),
    ]);
}

it('browses image attachments in a lightbox gallery', function () {
    // Newest first, so alpha is the newest: the gallery reads alpha, beta, gamma.
    $first = makeImageAttachment($this->task, 'alpha.png', now());
    makeImageAttachment($this->task, 'beta.png', now()->subMinute());
    makeImageAttachment($this->task, 'gamma.png', now()->subMinutes(2));

    $this->actingAs($this->member);

    $page = visit('/'.$this->project->short_name.'-'.$this->task->task_number);

    $page->assertMissing('@attachment-lightbox')
        ->click('@attachment-open-'.$first->id)
        ->assertVisible('@attachment-lightbox')
        ->assertSeeIn('@lightbox-counter', '1 / 3')
        ->assertSeeIn('@lightbox-name', 'alpha.png')
        ->click('@lightbox-next')
        ->assertSeeIn('@lightbox-counter', '2 / 3')
        ->assertSeeIn('@lightbox-name', 'beta.png')
        ->click('@lightbox-prev')
        ->assertSeeIn('@lightbox-counter', '1 / 3')
        ->click('@lightbox-close')
        ->assertMissing('@attachment-lightbox')
        ->assertNoJavascriptErrors();
});

it('navigates the lightbox with arrow keys and closes on escape', function () {
    $first = makeImageAttachment($this->task, 'alpha.png', now());
    makeImageAttachment($this->task, 'beta.png', now()->subMinute());

    $this->actingAs($this->member);

    $page = visit('/'.$this->project->short_name.'-'.$this->task->task_number);

    $page->click('@attachment-open-'.$first->id)
        ->assertSeeIn('@lightbox-counter', '1 / 2');

    $pressKey = static fn (string $key): string => <<<JS
        (() => {
            window.dispatchEvent(new KeyboardEvent('keydown', { key: '{$key}', bubbles: true }));
        })()
    JS;

    $page->script($pressKey('ArrowRight'));
    $page->assertSeeIn('@lightbox-counter', '2 / 2')
        ->assertSeeIn('@lightbox-name', 'beta.png');

    $page->script($pressKey('ArrowLeft'));
    $page->assertSeeIn('@lightbox-counter', '1 / 2');

    $page->script($pressKey('Escape'));
    $page->assertMissing('@attachment-lightbox')
        ->assertNoJavascriptErrors();
});

it('keeps non-image attachments as plain links outside the gallery', function () {
    makeImageAttachment($this->task, 'alpha.png');

    $pdf = Attachment::factory()->create([
        'attachable_id' => $this->task->id,
        'attachable_type' => $this->task->getMorphClass(),
        'disk' => 'attachments',
    ]);

    $this->actingAs($this->member);

    $page = visit('/'.$this->project->short_name.'-'.$this->task->task_number);

    $page->assertMissing('@attachment-open-'.$pdf->id)
        ->assertVisible('@attachment-link-'.$pdf->id)
        ->assertNoJavascriptErrors();
});
