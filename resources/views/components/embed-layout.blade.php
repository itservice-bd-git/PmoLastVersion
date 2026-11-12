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
    </head>
    <body data-embed class="font-sans antialiased bg-slate-50 text-slate-800">
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
