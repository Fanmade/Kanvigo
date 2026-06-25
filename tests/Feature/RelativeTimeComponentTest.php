<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;

it('shows the relative time with the absolute datetime revealed on hover', function () {
    $date = Carbon::parse('2026-06-20 14:30:00');

    $html = Blade::render('<x-relative-time :date="$date" />', ['date' => $date]);

    expect($html)
        ->toContain($date->diffForHumans())   // the visible "… ago" text
        ->toContain($date->isoFormat('LLL')); // the absolute datetime in the tooltip
});

it('renders nothing for a null date', function () {
    expect(trim(Blade::render('<x-relative-time :date="$date" />', ['date' => null])))->toBe('');
});
