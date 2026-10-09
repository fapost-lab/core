{{-- Applies the saved colour theme before the first paint. Same rule as resources/js/ui/shell/theme.ts; keep the storage key equal. Shared by the console and the builder. --}}
<script>
    (function () {
        try {
            var stored = window.localStorage.getItem('fapost-theme');
            var dark = stored === 'dark' || (stored !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
        } catch (e) {}
    })();
</script>
