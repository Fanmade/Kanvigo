<?php

use App\Models\Project;

it('derives a short name from a title', function (string $title, string $expected) {
    expect(Project::shortNameFromTitle($title))->toBe($expected);
})->with([
    'first three letters of one word' => ['Acme', 'ACM'],
    'short title shorter than three letters' => ['Hi', 'HI'],
    'initials of three words' => ['My Cool Project', 'MCP'],
    'initials capped at four words' => ['The Big Red Fox Jumps', 'TBRF'],
    'initials uppercased' => ['Customer Relationship Manager', 'CRM'],
    'non-letters dropped from a single word' => ['A1B2C3', 'ABC'],
    'extra whitespace ignored' => ['  Hello   World  Now  ', 'HWN'],
    'empty title yields empty string' => ['', ''],
]);
