@props(['file', 'signedUrl'])

<div class="flex justify-center">
    <video controls preload="metadata" class="max-h-[70vh] max-w-full rounded-lg shadow">
        {{-- Omit type attribute: video/quicktime is rejected by Chromium even for .mp4 files --}}
        <source src="{{ $signedUrl }}" />
        {{ __('Your browser does not support inline video playback.') }}
    </video>
</div>
