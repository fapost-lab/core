@props(['file', 'signedUrl'])

{{-- Browser-native PDF viewer via <embed>. Avoids shipping pdf.js client-side. --}}
<div class="flex justify-center">
    <embed
        src="{{ $signedUrl }}"
        type="application/pdf"
        class="h-[70vh] w-full rounded-lg border"
    />
</div>
