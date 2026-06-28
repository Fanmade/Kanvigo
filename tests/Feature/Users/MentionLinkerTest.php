<?php

use App\Models\User;
use App\Support\MentionLinker;
use App\Support\RichTextSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rewrites mention spans into links to the user profile', function () {
    $user = User::factory()->create();
    $html = '<p>Hi <span class="mention" data-type="mention" data-id="'.$user->id.'">@Ada</span>!</p>';

    expect(MentionLinker::link($html))
        ->toContain('<a')
        ->toContain('href="'.route('users.show', $user->public_id).'"')
        ->toContain('data-type="mention"')
        ->toContain('@Ada')
        // The opaque public id is used — never the numeric primary key.
        ->not->toContain('href="'.route('users.show', $user->id).'/"');
});

it('leaves content without mentions untouched', function () {
    $html = '<p>Nothing to link here</p>';

    expect(MentionLinker::link($html))->toBe($html);
});

it('ignores mention spans with a missing or invalid id', function () {
    $html = '<span class="mention" data-type="mention" data-id="0">@nobody</span>';

    expect(MentionLinker::link($html))->not->toContain('<a');
});

it('leaves a mention of an unknown user as plain text', function () {
    $html = '<span class="mention" data-type="mention" data-id="999999">@ghost</span>';

    expect(MentionLinker::link($html))->not->toContain('<a');
});

it('keeps the mention link after sanitization', function () {
    $user = User::factory()->create();
    $html = '<span class="mention" data-type="mention" data-id="'.$user->id.'">@Ada</span>';

    $rendered = app(RichTextSanitizer::class)->sanitize(MentionLinker::link($html));

    // The sanitizer entity-encodes the "@" (&#64;), so match the name only.
    expect($rendered)
        ->toContain('<a')
        ->toContain('users/'.$user->public_id)
        ->toContain('Ada');
});
