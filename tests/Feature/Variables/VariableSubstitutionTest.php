<?php

use App\Livewire\Tasks\TaskView;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Variable;
use App\Support\VariableSubstitutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'SCI']);
});

/**
 * Render content the way every surface does — through the shared rich-text
 * component, scoped to a project.
 */
function renderRichText(string $content, ?string $shortName = 'SCI', bool $variables = true): string
{
    return Blade::render(
        '<x-rich-text :content="$content" :short-name="$shortName" :variables="$variables" />',
        ['content' => $content, 'shortName' => $shortName, 'variables' => $variables],
    );
}

it('shows the current value where a variable is used', function () {
    Variable::factory()->for($this->project)->create(['name' => 'main_protagonist', 'value' => 'Robin Hood']);

    $html = renderRichText('<p>Our hero, [main_protagonist], walks through the door.</p>');

    expect($html)
        ->toContain('Our hero, ')
        ->toContain('<span class="variable" data-variable="main_protagonist">Robin Hood</span>')
        ->not->toContain('[main_protagonist]');
});

it('shows an unset variable as a hole in the prose', function () {
    Variable::factory()->for($this->project)->unset()->create(['name' => 'villain']);

    $html = renderRichText('<p>Enter [villain].</p>');

    expect($html)
        ->toContain('class="variable variable-unset"')
        ->toContain('data-variable="villain"')
        ->toContain('>villain</span>')
        ->not->toContain('[villain]');
});

it('leaves bracketed text that names no variable exactly as written', function (string $content) {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    expect(renderRichText("<p>{$content}</p>"))->toContain($content);
})->with([
    'a footnote marker' => 'See the note [1] below.',
    'a roman numeral' => 'Act [i], scene two.',
    'an undefined name' => 'The [sidekick] is unnamed.',
    'a bracketed sentence' => '[This is just an aside.]',
]);

it('never substitutes inside quoted code', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    $html = renderRichText('<p>Read <code>config[hero]</code> and set [hero].</p>');

    expect($html)
        ->toContain('<code>config[hero]</code>')
        ->toContain('>Robin Hood</span>');
});

it('does not substitute inside a value, so values cannot cycle', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'the [villain]']);
    Variable::factory()->for($this->project)->create(['name' => 'villain', 'value' => 'Sheriff']);

    expect(renderRichText('<p>[hero]</p>'))
        ->toContain('>the [villain]</span>')
        ->not->toContain('Sheriff');
});

it('escapes a value rather than trusting it as markup', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => '<script>alert(1)</script>']);

    expect(renderRichText('<p>[hero]</p>'))
        ->not->toContain('<script')
        ->toContain('&lt;script&gt;');
});

it('resolves a name only within its own project', function () {
    $other = Project::factory()->create(['short_name' => 'OTH']);
    Variable::factory()->for($other)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    expect(renderRichText('<p>[hero]</p>'))
        ->toContain('[hero]')
        ->not->toContain('Robin Hood');
});

it('leaves content outside a project namespace untouched', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    // A personal note, shown on the project page but belonging to no project.
    expect(renderRichText('<p>[hero]</p>', variables: false))->toContain('[hero]');
    expect(renderRichText('<p>[hero]</p>', shortName: null))->toContain('[hero]');
});

it('keeps mention links working alongside substitution', function () {
    $user = User::factory()->create(['name' => 'Marian']);
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    $html = renderRichText(
        '<p><span class="mention" data-type="mention" data-id="'.$user->id.'">@Marian</span> met [hero].</p>'
    );

    expect($html)
        ->toContain('<a class="mention"')
        ->toContain('>Robin Hood</span>');
});

it('substitutes in a task description but never in its title', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    $task = Task::factory()->for($this->project)->create([
        'title' => 'Introduce [hero]',
        'description' => '<p>Introduce [hero] in scene one.</p>',
    ]);

    Livewire::actingAs(userWithRole($this->project, 'member'))
        ->test(TaskView::class, [
            'short_name' => 'SCI',
            'task_number' => $task->task_number,
        ])
        ->assertSeeHtml('>Robin Hood</span>')
        // The title is an identifier — it must read the same in the board, search,
        // notifications and MCP output, so it stays literal.
        ->assertSeeHtml('Introduce [hero]');
});

it('costs no query when the content has no bracket', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    DB::flushQueryLog();
    DB::enableQueryLog();
    renderRichText('<p>Nothing to resolve here.</p>');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0);
});

it("loads a project's variables once however many usages a page shows", function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    $queriesForUsages = function (int $usages): int {
        // A fresh substitutor per measurement: the memo is per request.
        app()->forgetInstance(VariableSubstitutor::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        for ($i = 0; $i < $usages; $i++) {
            renderRichText('<p>[hero] again</p>');
        }

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($queriesForUsages(20))->toBeLessThanOrEqual($queriesForUsages(2));
});
