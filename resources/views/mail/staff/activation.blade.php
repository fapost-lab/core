<x-mail::message>
# {{ __('Hello :name', ['name' => $userName]) }}

{{ __('You have been invited to :app.', ['app' => config('app.name')]) }}

<x-mail::button :url="$activationUrl">
{{ __('Activate account') }}
</x-mail::button>

{{ __('If you did not expect this invitation, you may ignore this email.') }}

</x-mail::message>
