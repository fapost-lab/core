<x-mail::message>
  # {{ __('landing.email.greeting') }}

  <x-mail::table>
    | | |
    |:--|:--|
    | **{{ __('landing.email.fields.name') }}** | {{ $presale->name }} |
    | **{{ __('landing.email.fields.company') }}** | {{ $presale->company }} |
    | **{{ __('landing.email.fields.email') }}** | {{ $presale->email }} |
    | **{{ __('landing.email.fields.messenger') }}** | {{ $presale->messenger_preference->value }} |
    | **{{ __('landing.email.fields.message') }}** | {{ $presale->message ?? '—' }} |
    | **{{ __('landing.email.fields.ip') }}** | {{ $presale->ip_address ?? '—' }} |
    | **{{ __('landing.email.fields.locale') }}** | {{ $presale->locale }} |
    | **{{ __('landing.email.fields.submitted_at') }}** | {{ $presale->created_at->format('Y-m-d H:i:s') }} |
  </x-mail::table>
</x-mail::message>
