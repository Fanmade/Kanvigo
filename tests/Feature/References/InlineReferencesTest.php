<?php

use App\Enums\ReferenceOrigin;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Reference;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
});

describe('links written into rich text', function () {
    it('links a doc to the task its body references, and backlinks the task', function () {
        $task = Task::factory()->for($this->project)->create();
        $doc = Doc::factory()->for($this->project)->create([
            'body' => '<p>See '.inlineReference($task).' for the details.</p>',
        ]);

        expect($doc->references()->pluck('id')->all())->toBe([$task->id])
            ->and($task->referencedBy()->first()?->is($doc))->toBeTrue()
            ->and($doc->outgoingReferences()->first()?->origin)->toBe(ReferenceOrigin::Inline);
    });

    it('links a task to the doc its description references', function () {
        $doc = Doc::factory()->for($this->project)->create();
        $task = Task::factory()->for($this->project)->create([
            'description' => '<p>Follow '.inlineReference($doc).'.</p>',
        ]);

        expect($task->references()->first()?->is($doc))->toBeTrue()
            ->and($doc->referencedBy()->first()?->is($task))->toBeTrue();
    });

    it('keeps the reference markup through the save-time sanitizer', function () {
        $doc = Doc::factory()->for($this->project)->create();
        $task = Task::factory()->for($this->project)->create([
            'description' => '<p>'.inlineReference($doc).'</p>',
        ]);

        expect($task->description)->toContain('data-item-type="doc"')
            ->and($task->description)->toContain('data-id="'.$doc->id.'"');
    });

    it('drops the link once the reference is removed from the text', function () {
        $task = Task::factory()->for($this->project)->create();
        $doc = Doc::factory()->for($this->project)->create([
            'body' => '<p>'.inlineReference($task).'</p>',
        ]);

        $doc->update(['body' => '<p>Nothing to see here.</p>']);

        expect($doc->references())->toBeEmpty()
            ->and(Reference::count())->toBe(0);
    });

    it('re-syncs the links when the text is rewritten', function () {
        $first = Task::factory()->for($this->project)->create();
        $second = Task::factory()->for($this->project)->create();
        $doc = Doc::factory()->for($this->project)->create([
            'body' => '<p>'.inlineReference($first).'</p>',
        ]);

        $doc->update(['body' => '<p>'.inlineReference($second).'</p>']);

        expect($doc->references()->pluck('id')->all())->toBe([$second->id]);
    });

    it('reads a reference without an item type as a task', function () {
        $task = Task::factory()->for($this->project)->create();
        $doc = Doc::factory()->for($this->project)->create([
            'body' => '<p><a class="reference" data-type="reference" data-id="'.$task->id.'" href="/'.$task->reference.'">'.$task->reference.'</a></p>',
        ]);

        expect($doc->references()->first()?->is($task))->toBeTrue();
    });

    it('ignores a self-reference and an id that resolves to nothing', function () {
        $doc = Doc::factory()->for($this->project)->create();

        $doc->update([
            'body' => '<p>'.inlineReference($doc)
                .'<a class="reference" data-type="reference" data-item-type="task" data-id="99999" href="/ABC-9">ABC-9</a></p>',
        ]);

        expect($doc->references())->toBeEmpty();
    });

    it('links across projects when the text references another project\'s item', function () {
        $other = Project::factory()->create(['short_name' => 'XYZ']);
        $task = Task::factory()->for($other)->create();

        $doc = Doc::factory()->for($this->project)->create([
            'body' => '<p>'.inlineReference($task).'</p>',
        ]);

        expect($doc->references()->first()?->is($task))->toBeTrue();
    });
});

describe('curated links', function () {
    it('leaves a manual link alone when the text changes', function () {
        $manual = Task::factory()->for($this->project)->create();
        $mentioned = Task::factory()->for($this->project)->create();

        $doc = Doc::factory()->for($this->project)->create(['body' => '<p>Start.</p>']);
        $doc->addReference($manual);

        $doc->update(['body' => '<p>'.inlineReference($mentioned).'</p>']);

        expect($doc->references()->pluck('id')->sort()->values()->all())
            ->toBe(collect([$manual->id, $mentioned->id])->sort()->values()->all());
    });

    it('keeps the manual origin when the text also references the item', function () {
        $task = Task::factory()->for($this->project)->create();

        $doc = Doc::factory()->for($this->project)->create(['body' => '<p>Start.</p>']);
        $doc->addReference($task);

        $doc->update(['body' => '<p>'.inlineReference($task).'</p>']);

        expect(Reference::count())->toBe(1)
            ->and(Reference::firstOrFail()->origin)->toBe(ReferenceOrigin::Manual);

        // …and stays linked when the text drops the reference again.
        $doc->update(['body' => '<p>Start.</p>']);

        expect($doc->references()->first()?->is($task))->toBeTrue();
    });

    it('defaults a directly added link to the manual origin', function () {
        $doc = Doc::factory()->for($this->project)->create();
        $task = Task::factory()->for($this->project)->create();

        expect($doc->addReference($task)->origin)->toBe(ReferenceOrigin::Manual);
    });
});
