@props(['file', 'signedUrl'])

<div class="flex justify-center">
    <img
        src="{{ $signedUrl }}"
        alt="{{ $file->name }}"
        loading="lazy"
        class="max-h-[70vh] max-w-full rounded-lg shadow"
    />
</div>
