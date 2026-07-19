<?php

use App\Models\Doc;
use App\Models\Project;
use App\Models\User;
use App\Support\ReferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

describe('doc model', function () {
    it('assigns a per-project doc number and builds the PROJ-D reference', function () {
        $project = Project::factory()->create(['short_name' => 'ABC']);
        $other = Project::factory()->create(['short_name' => 'XYZ']);

        $first = Doc::factory()->for($project)->create();
        $second = Doc::factory()->for($project)->create();
        $elsewhere = Doc::factory()->for($other)->create();

        expect($first->doc_number)->toBe(1)
            ->and($second->doc_number)->toBe(2)
            ->and($elsewhere->doc_number)->toBe(1)
            ->and($first->reference)->toBe('ABC-D1')
            ->and($second->reference)->toBe('ABC-D2')
            ->and($elsewhere->reference)->toBe('XYZ-D1');
    });

    it('defaults to a private draft attached to its project', function () {
        $doc = Doc::factory()->create();

        expect($doc->is_public)->toBeFalse()
            ->and($doc->project)->toBeInstanceOf(Project::class);
    });

    it('sanitizes the body on save', function () {
        $doc = Doc::factory()->create(['body' => '<p>Keep</p><script>alert(1)</script>']);

        expect($doc->body)->toContain('Keep')
            ->and($doc->body)->not->toContain('<script>');
    });

    it('records a content audit event on creation', function () {
        $doc = Doc::factory()->create();

        expect(DB::table('audit_outbox')
            ->where('event->action', 'created')
            ->where('event->subject_type', $doc->getMorphClass())
            ->where('event->subject_id', $doc->id)
            ->exists())->toBeTrue();
    });

    it('can be tagged, scoped to its project', function () {
        $doc = Doc::factory()->create();

        $doc->syncTags(['design', 'lore']);

        expect($doc->load('tags')->tags->pluck('name')->sort()->values()->all())->toBe(['design', 'lore']);
    });
});

describe('doc nesting', function () {
    it('nests a doc under a parent in the same project', function () {
        $project = Project::factory()->create();
        $parent = Doc::factory()->for($project)->create();
        $child = Doc::factory()->childOf($parent)->create();

        expect($child->parent->is($parent))->toBeTrue()
            ->and($parent->children->pluck('id')->all())->toBe([$child->id]);
    });

    it('rejects a parent in a different project', function () {
        $parent = Doc::factory()->create();
        $other = Project::factory()->create();

        expect(fn () => Doc::factory()->for($other)->create(['parent_id' => $parent->id]))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects a doc as its own parent', function () {
        $doc = Doc::factory()->create();
        $doc->parent_id = $doc->id;

        expect(fn () => $doc->save())->toThrow(InvalidArgumentException::class);
    });

    it('rejects a cycle', function () {
        $project = Project::factory()->create();
        $a = Doc::factory()->for($project)->create();
        $b = Doc::factory()->childOf($a)->create();

        $a->parent_id = $b->id;

        expect(fn () => $a->save())->toThrow(InvalidArgumentException::class);
    });

    it('rejects nesting beyond the max depth', function () {
        $project = Project::factory()->create();
        $node = Doc::factory()->for($project)->create();

        for ($level = 2; $level <= Doc::MAX_NESTING_DEPTH; $level++) {
            $node = Doc::factory()->childOf($node)->create();
        }

        expect(fn () => Doc::factory()->childOf($node)->create())
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('doc access', function () {
    it('lets an editor see drafts and published docs and edit them', function () {
        $project = Project::factory()->create(['short_name' => 'ABC']);
        $member = User::factory()->create();
        joinProject($project, $member, 'member');

        $draft = Doc::factory()->for($project)->create(['is_public' => false]);
        $published = Doc::factory()->for($project)->published()->create();

        expect($member->can('view', $draft))->toBeTrue()
            ->and($member->can('view', $published))->toBeTrue()
            ->and($member->can('update', $draft))->toBeTrue()
            ->and($member->can('delete', $draft))->toBeTrue()
            ->and($member->can('tag', $draft))->toBeTrue()
            ->and($member->can('create-doc', $project))->toBeTrue();
    });

    it('lets a viewer see only published docs and not edit them', function () {
        $project = Project::factory()->create(['short_name' => 'ABC']);
        $viewer = User::factory()->create();
        joinProject($project, $viewer, 'viewer');

        $draft = Doc::factory()->for($project)->create(['is_public' => false]);
        $published = Doc::factory()->for($project)->published()->create();

        expect($viewer->can('view', $published))->toBeTrue()
            ->and($viewer->can('view', $draft))->toBeFalse()
            ->and($viewer->can('update', $published))->toBeFalse()
            ->and($viewer->can('delete', $published))->toBeFalse()
            ->and($viewer->can('create-doc', $project))->toBeFalse();
    });

    it('hides docs from a non-member', function () {
        $project = Project::factory()->create(['short_name' => 'ABC']);
        $stranger = User::factory()->create();

        $published = Doc::factory()->for($project)->published()->create();

        expect($stranger->can('view', $published))->toBeFalse();
    });
});

describe('doc references', function () {
    it('resolves a PROJ-D reference to its doc', function () {
        $project = Project::factory()->create(['short_name' => 'ABC']);
        $doc = Doc::factory()->for($project)->create();

        expect(ReferenceResolver::doc('ABC-D'.$doc->doc_number)?->is($doc))->toBeTrue();
    });

    it('returns null for a malformed, task-shaped or unknown reference', function () {
        Project::factory()->create(['short_name' => 'ABC']);

        expect(ReferenceResolver::doc('ABC-42'))->toBeNull()
            ->and(ReferenceResolver::doc('ABC-D999'))->toBeNull()
            ->and(ReferenceResolver::doc('nonsense'))->toBeNull();
    });
});
