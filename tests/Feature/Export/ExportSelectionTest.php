<?php

use App\Livewire\Tasks\TaskView;
use App\Models\Project;
use App\Models\Task;
use App\Support\Export\ExportOptions;
use App\Support\Export\MarkdownExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['kanvigo.tasks.max_depth' => 5]);

    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->member = userWithRole($this->project, 'member');
    $this->actingAs($this->member);

    $this->root = Task::factory()->for($this->project)->create(['title' => 'Root', 'description' => null]);
    $this->child = Task::factory()->for($this->project)->childOf($this->root)->create(['title' => 'Child', 'description' => null]);
    $this->grandchild = Task::factory()->for($this->project)->childOf($this->child)->create(['title' => 'Grandchild', 'description' => null]);
    $this->sibling = Task::factory()->for($this->project)->childOf($this->root)->create(['title' => 'Sibling', 'description' => null]);
});

/** The heading lines of the root task exported with a hand-picked selection. */
function selectedHeadings(array $only): array
{
    $markdown = app(MarkdownExporter::class)->render(test()->root->fresh(), new ExportOptions(
        metadata: false,
        descendants: true,
        only: $only,
    ));

    return array_values(array_filter(explode("\n", $markdown), static fn (string $line): bool => str_starts_with($line, '#')));
}

/** The selection key for an item. */
function key_for(Task $task): string
{
    return app(MarkdownExporter::class)->selectionKey($task);
}

describe('rendering a hand-picked selection', function () {
    it('includes exactly what was picked', function () {
        expect(selectedHeadings([key_for($this->child)]))->toBe(['# Root', '## Child']);
    });

    it('promotes a kept item whose parent was left out, so headings never skip', function () {
        // Child is unticked, its own child kept: the survivor moves up to where
        // the gap is rather than rendering at "###" under nothing.
        expect(selectedHeadings([key_for($this->grandchild)]))->toBe(['# Root', '## Grandchild']);
    });

    it('keeps the real depth when the chain is intact', function () {
        expect(selectedHeadings([key_for($this->child), key_for($this->grandchild)]))
            ->toBe(['# Root', '## Child', '### Grandchild']);
    });

    it('exports the item alone when nothing below it is picked', function () {
        expect(selectedHeadings([]))->toBe(['# Root']);
    });

    it('still applies the ordinary filters to a picked item', function () {
        $canceled = Task::factory()->for($this->project)->childOf($this->root)->canceled()->create(['title' => 'Abandoned']);

        // Picked, but canceled and canceled items were not asked for.
        expect(selectedHeadings([key_for($canceled), key_for($this->sibling)]))
            ->toBe(['# Root', '## Sibling']);
    });
});

describe('picking in the dialog', function () {
    it('starts from what the depth select currently covers', function () {
        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->root->task_number])
            ->call('startExport')
            ->set('exportDescendants', true)
            ->set('exportDepth', '1')
            ->call('startPickingExportItems')
            // Depth 1 covers the two direct children, not the grandchild.
            ->assertSet('exportOnly', [key_for($this->child), key_for($this->sibling)]);
    });

    it('unticks a whole branch at once, and ticks it back', function () {
        $component = Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->root->task_number])
            ->call('startExport')
            ->set('exportDescendants', true)
            ->call('startPickingExportItems');

        $component->call('toggleExportItem', key_for($this->child))
            ->assertSet('exportOnly', [key_for($this->sibling)]);

        $component->call('toggleExportItem', key_for($this->child));

        expect($component->get('exportOnly'))
            ->toContain(key_for($this->child))
            ->toContain(key_for($this->grandchild))
            ->toContain(key_for($this->sibling));
    });

    it('shows the tree only while picking, and the depth select otherwise', function () {
        $component = Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->root->task_number])
            ->call('startExport')
            ->set('exportDescendants', true);

        $component->assertSeeHtml('data-test="export-depth"')
            ->assertDontSeeHtml('data-test="export-tree"')
            ->call('startPickingExportItems')
            ->assertSeeHtml('data-test="export-tree"')
            ->assertSeeHtml('data-test="export-item-'.key_for($this->grandchild).'"')
            ->assertDontSeeHtml('data-test="export-depth"')
            ->call('stopPickingExportItems')
            ->assertSet('exportOnly', null)
            ->assertSeeHtml('data-test="export-depth"');
    });

    it('exports the picked set and records that a selection was used', function () {
        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->root->task_number])
            ->call('startExport')
            ->set('exportDescendants', true)
            ->call('startPickingExportItems')
            ->call('toggleExportItem', key_for($this->child))
            ->call('copyExport')
            ->assertDispatched('export-copied', function (string $_event, array $params): bool {
                return str_contains($params['markdown'], '## Sibling')
                    && ! str_contains($params['markdown'], 'Child');
            });

        $event = json_decode((string) DB::table('audit_outbox')->orderByDesc('id')->value('event'), true);

        expect($event['metadata']['selection'])->toBe('1');
    });

    it('reopens on the quick path rather than restoring a set picked elsewhere', function () {
        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->root->task_number])
            ->call('startExport')
            ->set('exportDescendants', true)
            ->call('startPickingExportItems')
            ->call('copyExport');

        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->root->task_number])
            ->call('startExport')
            ->assertSet('exportOnly', null);
    });
});
