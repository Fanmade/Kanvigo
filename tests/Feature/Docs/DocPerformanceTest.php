<?php

use App\Livewire\Docs\DocList;
use App\Livewire\Docs\DocView;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->editor = userWithRole($this->project, 'member');
});

/**
 * The number of queries a Livewire render issues, measured on a clean log.
 */
function queriesWhileRendering(callable $render): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $render();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('renders the doc tree with a query budget that does not grow with the docs', function () {
    $renderTree = function (int $docs): int {
        $project = Project::factory()->create();
        $member = userWithRole($project, 'member');

        $parent = Doc::factory()->for($project)->create();

        for ($index = 0; $index < $docs; $index++) {
            Doc::factory()->childOf($parent)->create();
        }

        return queriesWhileRendering(static fn () => Livewire::actingAs($member)
            ->test(DocList::class, ['short_name' => $project->short_name])
            ->html());
    };

    // 20 nested docs must cost no more queries than 2: the tree is grouped in
    // memory from one bulk load, not walked parent by parent.
    expect($renderTree(20))->toBeLessThanOrEqual($renderTree(2));
});

it('renders a doc page with a query budget that does not grow with its links', function () {
    $renderLinks = function (int $links): int {
        $project = Project::factory()->create();
        $member = userWithRole($project, 'member');
        $doc = Doc::factory()->for($project)->create();

        for ($index = 0; $index < $links; $index++) {
            $doc->addReference(Task::factory()->for($project)->create());
            Doc::factory()->for($project)->create()->addReference($doc);
        }

        return queriesWhileRendering(static fn () => Livewire::actingAs($member)
            ->test(DocView::class, ['short_name' => $project->short_name, 'doc_number' => $doc->doc_number])
            ->html());
    };

    // The links and backlinks panels resolve their items in bulk, so ten of each
    // cost no more queries than one.
    expect($renderLinks(10))->toBeLessThanOrEqual($renderLinks(1));
});

it('renders a doc page with a query budget that does not grow with its nested docs', function () {
    $renderChildren = function (int $children): int {
        $project = Project::factory()->create();
        $member = userWithRole($project, 'member');
        $doc = Doc::factory()->for($project)->create();

        for ($index = 0; $index < $children; $index++) {
            Doc::factory()->childOf($doc)->create();
        }

        return queriesWhileRendering(static fn () => Livewire::actingAs($member)
            ->test(DocView::class, ['short_name' => $project->short_name, 'doc_number' => $doc->doc_number])
            ->html());
    };

    expect($renderChildren(20))->toBeLessThanOrEqual($renderChildren(2));
});
