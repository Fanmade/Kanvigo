<x-mail::message>
# {{ $reference !== null ? $reference : config('app.name') }}

@if ($title)
**{{ $title }}**
@endif

{{ $actor }} {{ $description }}

@if ($url)
<x-mail::button :url="$url">
{{ __('Open in Kanvigo') }}
</x-mail::button>
@endif

{{ __('You are receiving this because you subscribed to this item and asked for e-mail updates.') }}

{{ __('Thanks') }},<br>
{{ config('app.name') }}
</x-mail::message>
