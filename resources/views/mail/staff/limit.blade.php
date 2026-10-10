<x-mail::message>
# {{ $title }}

{{ __('limits.mail.greeting', ['name' => $recipientName]) }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
<x-mail::button :url="$actionUrl">
{{ $actionLabel }}
</x-mail::button>

</x-mail::message>
