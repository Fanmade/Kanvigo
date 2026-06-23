<x-mail::message>
# {{ __('You have been invited to Kanvigo') }}

{{ __(':name has invited you to collaborate on Kanvigo.', ['name' => $inviterName]) }}

{{ __('Click the button below to choose a username, set your password, and configure your security settings.') }}

<x-mail::button :url="$acceptUrl">
{{ __('Accept invitation') }}
</x-mail::button>

{{ __('This invitation link will expire on :date.', ['date' => $invitation->expires_at->toDayDateTimeString()]) }}

{{ __('Thanks') }},<br>
{{ config('app.name') }}
</x-mail::message>
