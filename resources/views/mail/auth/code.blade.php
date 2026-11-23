<x-mail::message>
# {{ __('auth_code.heading', ['brand' => $brand]) }}

{{ __('auth_code.intro') }}

<x-mail::panel>
**{{ $code }}**
</x-mail::panel>

{{ trans_choice('auth_code.expires', $minutes, ['minutes' => $minutes]) }}

{{ __('auth_code.ignore') }}
</x-mail::message>
