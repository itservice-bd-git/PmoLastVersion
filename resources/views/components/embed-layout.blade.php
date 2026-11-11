{{-- A page shown inside another page (Planning's รอบส่ง window opens งานจัดส่ง in a frame): the app's head and styles,
     no menu, top bar or bell around it. A $header slot is ignored. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="login-url" content="{{ route('login') }}">
        <title>{{ config('app.name', 'Avatar Electric PMO') }}</title>
        <link rel="stylesheet" href="{{ asset('fonts/anuphan/anuphan.css') }}">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <script>
            // Inside Planning's รอบส่ง window (planning/partials/_overlays): tell it when one of this page's panels opens or
            // closes (its header dims, one ✕ to press), and when Esc is pressed with none open (the window closes). A panel
            // open = the body locked by it (overflow-hidden / overflow-y-hidden) or the photo viewer on screen.
            (function () {
                if (window.parent === window) return;
                const tell = (m) => window.parent.postMessage(m, location.origin);
                const busy = () => document.body.classList.contains('overflow-hidden') || document.body.classList.contains('overflow-y-hidden') || !!document.querySelector('[data-file-viewer]');
                // capture, registered before Alpine's: what is open is read before Esc closes it
                window.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !busy()) tell({ type: 'pmo-ship-close' }); }, true);
                document.addEventListener('DOMContentLoaded', () => {
                    let open = false;
                    new MutationObserver(() => { const now = busy(); if (now !== open) { open = now; tell({ type: 'pmo-ship-panel', open }); } })
                        .observe(document.body, { attributes: true, attributeFilter: ['class'], childList: true, subtree: true });
                    // the round that was pressed, in view
                    const picked = document.querySelector('[data-picked]');
                    if (picked) picked.scrollIntoView({ block: 'center' });
                });
            })();
        </script>
    </head>
    <body class="font-sans antialiased bg-slate-50 text-slate-800">
        @if (session('success'))
            <div class="mx-4 mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="mx-4 mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
        @endif
        <main class="p-4">
            {{ $slot }}
        </main>
        @stack('scripts')
    </body>
</html>
