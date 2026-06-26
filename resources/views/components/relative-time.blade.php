@props(['date'])

{{--
    A relative timestamp ("5 hours ago") that reveals the absolute date and time
    on hover. Used wherever a humanised time is shown (activity log, comments,
    notifications, task metadata). Renders nothing for a null date, mirroring the
    `$date?->diffForHumans()` it replaces.

    Uses a native `title` rather than a Flux tooltip: Flux tooltips attach to a
    focusable trigger (a button or link), so they don't fire for plain inline
    text — and `title` works reliably on hover without adding a tab stop to every
    timestamp in a long activity feed.
--}}
@if ($date)
    @php($carbon = $date instanceof \Carbon\CarbonInterface ? $date : \Illuminate\Support\Carbon::parse($date))
    <span {{ $attributes->class('cursor-help') }} title="{{ $carbon->isoFormat('LLL') }}">{{ $carbon->diffForHumans() }}</span>
@endif
