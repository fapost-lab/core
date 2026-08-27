@props(['file', 'signedUrl'])

<div class="flex justify-center">
    <audio controls preload="metadata" class="w-full max-w-md">
        <source src="{{ $signedUrl }}" />
        {{ __('Your browser does not support inline audio playback.') }}
    </audio>
</div>
