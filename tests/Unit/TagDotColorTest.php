<?php

use App\Models\Tag;

it('maps every palette color to its dot background and foreground class', function () {
    foreach (Tag::PALETTE as $color) {
        expect(Tag::dotBackgroundClass($color))->toBe("bg-{$color}-500")
            ->and(Tag::dotForegroundClass($color))->toBe("text-{$color}-500");
    }
});

it('falls back to a neutral class for the default and unknown colors', function () {
    expect(Tag::dotBackgroundClass(Tag::DEFAULT_COLOR))->toBe('bg-zinc-400')
        ->and(Tag::dotForegroundClass(Tag::DEFAULT_COLOR))->toBe('text-zinc-400')
        ->and(Tag::dotBackgroundClass('chartreuse'))->toBe('bg-zinc-400');
});
