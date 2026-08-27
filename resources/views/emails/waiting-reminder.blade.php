<x-mail::message>
# {{ trans_choice('{1} A request is waiting for your answer|[2,*] :count requests are waiting for your answer', $count, ['count' => $count]) }}

{{ __('In :project, the following is still waiting on you:', ['project' => $project->short_name.' · '.$project->title]) }}

@foreach ($requests as $request)
- **{{ $request->reference }}** {{ $request->title }}@if ($request->waiting_since) — {{ __('waiting since :since', ['since' => $request->waiting_since->diffForHumans()]) }}@endif

@endforeach

<x-mail::button :url="$url">
{{ __('Answer them') }}
</x-mail::button>

{{ __('You are receiving this because you subscribed to this item and asked for e-mail updates.') }}

{{ __('Thanks') }},<br>
{{ config('app.name') }}
</x-mail::message>
