<x-mail::message>
# {{ __('Hello :name', ['name' => $recipientName]) }}

{{ $messageBody }}

@if ($actionUrl)
<x-mail::button :url="$actionUrl">
{{ $actionLabel }}
</x-mail::button>
@endif

</x-mail::message>
