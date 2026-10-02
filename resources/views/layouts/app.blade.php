<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Avatar Electric PMO') }}</title>
        <link rel="icon" type="image/png" href="{{ asset('images/logo.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        {{-- Restore the desktop sidebar's collapsed/expanded state before first paint
             (classic dark-mode-style FOUC fix) - a deferred Alpine mount would otherwise
             show the sidebar expanded for a moment before snapping to collapsed. Synced
             going forward by toggleSidebar() below, which keeps this same class current. --}}
        <script>
            (function () {
                try {
                    if (localStorage.getItem('avatar_pmo_sidebar_collapsed') === '1') {
                        document.documentElement.classList.add('sidebar-collapsed');
                    }
                } catch (e) { /* ignore - private mode / blocked storage, falls back to expanded */ }
            })();
        </script>
    </head>
    <body class="font-sans antialiased bg-slate-50 text-slate-800">
        <div class="min-h-screen flex"
             x-data="{
                 mobileNavOpen: false,
                 sidebarCollapsed: document.documentElement.classList.contains('sidebar-collapsed'),
                 toggleSidebar() {
                     this.sidebarCollapsed = !this.sidebarCollapsed;
                     document.documentElement.classList.toggle('sidebar-collapsed', this.sidebarCollapsed);
                     try {
                         localStorage.setItem('avatar_pmo_sidebar_collapsed', this.sidebarCollapsed ? '1' : '0');
                     } catch (e) { /* ignore - private mode / blocked storage, state just won't persist */ }
                 },
             }">
            <!-- Sidebar - width/offset driven entirely by the html.sidebar-collapsed
                 class (see resources/css/app.css); sidebarCollapsed here only mirrors
                 that class for the toggle button's icon/aria state below. -->
            <aside id="app-sidebar" class="hidden lg:flex lg:flex-col lg:w-64 lg:fixed lg:inset-y-0 z-40 border-r border-black/20 bg-ae-navy transition-all duration-200 ease-in-out relative">
                @include('layouts.sidebar-content')

                <button type="button" @click="toggleSidebar()"
                        class="hidden lg:flex absolute -right-3.5 top-5 w-7 h-7 items-center justify-center rounded-full bg-ae-navy border border-white/25 text-slate-300 shadow-md shadow-black/30 hover:text-white hover:bg-ae-navylight transition"
                        :aria-label="sidebarCollapsed ? 'ขยายเมนูด้านข้าง' : 'ย่อเมนูด้านข้าง'"
                        :aria-expanded="(!sidebarCollapsed).toString()">
                    <svg x-cloak x-show="!sidebarCollapsed" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                    <svg x-cloak x-show="sidebarCollapsed" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                </button>
            </aside>

            <!-- Mobile sidebar - independent of the desktop collapse state above
                 (always full-width drawer; collapse is a desktop-only affordance). -->
            <div x-show="mobileNavOpen" x-cloak class="lg:hidden fixed inset-0 z-40 flex">
                <div class="fixed inset-0 bg-slate-900/50" @click="mobileNavOpen = false"></div>
                <aside class="relative flex flex-col w-64 bg-ae-navy border-r border-black/20">
                    @include('layouts.sidebar-content')
                </aside>
            </div>

            <div id="app-content" class="flex-1 lg:pl-64 flex flex-col min-w-0 transition-all duration-200 ease-in-out">
                <!-- Topbar -->
                <header class="sticky top-0 z-30 bg-white border-b border-slate-200">
                    <div class="flex items-center justify-between h-16 px-4 sm:px-6 lg:px-8">
                        <div class="flex items-center gap-3 min-w-0">
                            <button @click="mobileNavOpen = true" class="lg:hidden text-slate-500 hover:text-slate-700">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
                            </button>
                            @isset($header)
                                <div class="min-w-0">{{ $header }}</div>
                            @endisset
                        </div>

                        <div class="flex items-center gap-4">
                            <x-dropdown align="right" width="48">
                                <x-slot name="trigger">
                                    <button class="flex items-center gap-2 text-sm font-medium text-slate-600 hover:text-slate-900">
                                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-600 text-white text-xs font-semibold">
                                            {{ strtoupper(substr(auth()->user()->email ?? 'U', 0, 1)) }}
                                        </span>
                                        {{-- `email` is the username field for now (accounts are plain usernames,
                                             not real email addresses - see LoginRequest) - shown here instead
                                             of the full name so the header always matches what was typed to log in. --}}
                                        <span class="hidden sm:inline">{{ auth()->user()->email }}</span>
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                    </button>
                                </x-slot>
                                <x-slot name="content">
                                    <x-dropdown-link :href="route('profile.edit')">โปรไฟล์ของฉัน</x-dropdown-link>
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                                            ออกจากระบบ
                                        </x-dropdown-link>
                                    </form>
                                </x-slot>
                            </x-dropdown>
                        </div>
                    </div>
                </header>

                @if (session('success'))
                    <div class="mx-4 sm:mx-6 lg:mx-8 mt-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 text-sm">
                        {{ session('success') }}
                    </div>
                @endif
                @if (session('error'))
                    <div class="mx-4 sm:mx-6 lg:mx-8 mt-4 rounded-lg bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm">
                        {{ session('error') }}
                    </div>
                @endif

                <main class="flex-1 p-4 sm:p-6 lg:p-8">
                    {{ $slot }}
                </main>
            </div>
        </div>
        @stack('scripts')
    </body>
</html>
