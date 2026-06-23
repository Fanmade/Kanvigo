<?php

use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function loadBackfillTagColorsMigration(): object
{
    return require database_path('migrations/2026_06_23_105636_backfill_tag_colors.php');
}

it('gives previously-grey tags their deterministic palette color', function () {
    // Two legacy tags left on the "zinc" default by the add-color migration.
    // Insert directly so the model's creating hook doesn't assign a color.
    DB::table('tags')->insert([
        ['name' => 'Bug', 'color' => 'zinc', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Feature', 'color' => 'zinc', 'created_at' => now(), 'updated_at' => now()],
    ]);

    loadBackfillTagColorsMigration()->up();

    $bug = Tag::where('name', 'Bug')->firstOrFail();
    $feature = Tag::where('name', 'Feature')->firstOrFail();

    expect($bug->color)->toBe(Tag::colorForName('Bug'))
        ->and($bug->color)->not->toBe('zinc')
        ->and($feature->color)->toBe(Tag::colorForName('Feature'))
        ->and(Tag::PALETTE)->toContain($bug->color);
});

it('leaves tags that already have a non-default color untouched', function () {
    DB::table('tags')->insert([
        'name' => 'Custom', 'color' => 'emerald', 'created_at' => now(), 'updated_at' => now(),
    ]);

    loadBackfillTagColorsMigration()->up();

    expect(Tag::where('name', 'Custom')->value('color'))->toBe('emerald');
});
