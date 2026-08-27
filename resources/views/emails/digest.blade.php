<x-mail::message>
# {{ trans_choice('{1} One update you have not read|[2,*] :count updates you have not read', $count, ['count' => $count]) }}

{{ __('Here is what happened on the projects and tasks you follow since your last digest.') }}

@foreach ($lines as $line)
- **{{ $line['reference'] }}**@if ($line['title']) {{ $line['title'] }}@endif — {{ $line['text'] }}

@endforeach

<x-mail::button :url="$url">
{{ __('Open your notifications') }}
</x-mail::button>

{{ __('You are receiving this because you asked for a :period digest. You can change that in your notification settings.', ['period' => $period]) }}

{{ __('Thanks') }},<br>
{{ config('app.name') }}
</x-mail::message>
