<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="login-url" content="{{ route('login') }}">{{-- resources/js/session-expiry.js: a call sent here = logged out --}}

        <title>{{ config('app.name', 'Avatar Electric PMO') }}</title>
        <link rel="icon" type="image/png" href="{{ asset('images/logo.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">

        <link rel="stylesheet" href="{{ asset('fonts/anuphan/anuphan.css') }}">

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
                            <button @click="mobileNavOpen = true" class="lg:hidden -ml-2 inline-flex h-11 w-11 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700" aria-label="เปิดเมนู">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
                            </button>
                            @isset($header)
                                <div class="min-w-0">{{ $header }}</div>
                            @endisset
                        </div>

                        <div class="flex items-center gap-1 sm:gap-2">   {{-- 44px places to tap (phones, gloves), the icons as they were --}}
                            @if (auth()->user()->isViewOnly())
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200" title="แผนกนี้ตั้งเป็นดูได้อย่างเดียว (ตั้งค่า → สิทธิ์การใช้งาน)">👁 ดูได้อย่างเดียว</span>
                            @endif
                            <button type="button" @click="$dispatch('open-status-report')" class="inline-flex h-11 w-11 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700" title="รายงานสถานะงาน" aria-label="รายงานสถานะงาน">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                            </button>
                            {{-- the bell: drawn from the server's data, then asks again every 15 s (and when the tab comes back), so new
                                 notices show without a reload; a new urgent one (tag / alert / new work / overdue) opens the alert window.
                                 Opening it = "เห็นแล้ว" (the red number resets); "ต้องทำ" waits for "รับทราบ" (NotificationController) --}}
                            <div class="relative" x-data="pmoBell(@js(['feed' => \App\Http\Controllers\NotificationController::feedFor(auth()->user()), 'url' => route('notifications.feed'), 'read' => route('notifications.read', ['id' => '__ID__']), 'ack' => route('notifications.ack', ['id' => '__ID__']), 'seen' => route('notifications.seen'), 'ackAll' => route('notifications.read-all')]))"
                                 x-init="init()" @click.outside="close()" @keydown.escape.window="close()" @pmo-bell-refresh.window="refresh()">
                                <button type="button" @click="toggle()" class="relative inline-flex h-11 w-11 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700" aria-label="การแจ้งเตือน" :aria-expanded="open">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0a3 3 0 11-6 0" /></svg>
                                    <span x-show="feed.unread > 0" x-cloak class="absolute right-1 top-1 min-w-[16px] h-4 px-1 rounded-full bg-red-500 text-white text-[11.5px] font-semibold leading-4 text-center" x-text="feed.unread > 99 ? '99+' : feed.unread"></span>
                                </button>
                                <div x-show="open" x-cloak x-transition.origin.top.right class="fixed inset-x-2 top-16 z-50 flex max-h-[calc(100dvh-5rem)] flex-col sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:max-h-none sm:w-[26rem] rounded-xl border border-slate-200 bg-white shadow-xl">
                                    <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-2.5">
                                        <span class="text-sm font-bold text-slate-900">การแจ้งเตือน</span>
                                        <div class="flex gap-0.5 rounded-lg bg-slate-100 p-0.5 text-[13px] font-semibold">
                                            <button type="button" @click="tab = 'todo'" class="min-h-[2.25rem] rounded-md px-3" :class="tab === 'todo' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'" x-text="'ต้องทำ' + (feed.todoOpen ? ' (' + feed.todoOpen + ')' : '')"></button>
                                            <button type="button" @click="tab = 'all'" class="min-h-[2.25rem] rounded-md px-3" :class="tab === 'all' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'">ทั้งหมด</button>
                                        </div>
                                    </div>
                                    <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain sm:max-h-[70vh] sm:flex-none">
                                        <p class="flex items-center gap-2 px-4 pb-1 pt-2.5 text-[12px] font-bold text-red-800"><span class="h-2 w-2 rounded-full bg-red-600"></span>ต้องทำ</p>
                                        <template x-for="n in feed.todo" :key="n.id">
                                            <div class="flex gap-2.5 border-t border-slate-50 px-4 py-2.5" :class="[isNew(n) ? 'bg-orange-50' : '', n.acked ? 'opacity-60' : '']">
                                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="isNew(n) ? 'bg-orange-500' : (n.acked ? '' : 'ring-2 ring-inset ring-slate-400')"></span>
                                                <div class="min-w-0 flex-1">
                                                    <form method="POST" :action="cfg.read.replace('__ID__', n.id)">
                                                        @csrf
                                                        <button class="block w-full text-left hover:underline">
                                                            <span class="block text-sm font-semibold text-slate-900" x-text="n.title"></span>
                                                            <span class="block text-xs text-slate-600 line-clamp-2" x-text="n.body"></span>
                                                        </button>
                                                    </form>
                                                    <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[12px]">
                                                        <span class="text-slate-400" x-text="n.at"></span>
                                                        <span class="font-semibold" :class="n.acked ? 'text-emerald-700' : (isNew(n) ? 'text-orange-700' : 'text-slate-500')" x-text="n.acked ? 'รับทราบแล้ว ' + (n.ackedAt || '') : (isNew(n) ? 'ใหม่' : 'เห็นแล้ว')"></span>
                                                        <span class="ml-auto flex gap-1.5" x-show="!n.acked">
                                                            <a x-show="n.acceptUrl" :href="n.acceptUrl" class="inline-flex min-h-[2.75rem] items-center rounded-md border border-[#0F4C81] px-3 font-semibold text-[#0F4C81] hover:bg-blue-50 sm:min-h-0 sm:py-1">รับงาน</a>
                                                            <button type="button" @click="ack(n)" class="inline-flex min-h-[2.75rem] items-center rounded-md bg-[#0F4C81] px-3 font-semibold text-white hover:bg-[#0A3760] sm:min-h-0 sm:py-1">รับทราบ</button>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                        <p x-show="!feed.todo.length" class="px-4 py-3 text-sm text-slate-400">ไม่มีเรื่องที่ต้องทำ ✓</p>
                                        <template x-if="tab === 'all'">
                                            <div>
                                                <p class="flex items-center gap-2 border-t border-slate-100 px-4 pb-1 pt-3 text-[12px] font-bold text-blue-800"><span class="h-2 w-2 rounded-full bg-blue-600"></span>ความเคลื่อนไหว</p>
                                                <template x-for="n in feed.feed" :key="n.id">
                                                    <form method="POST" :action="cfg.read.replace('__ID__', n.id)" class="border-t border-slate-50" :class="isNew(n) ? 'bg-orange-50' : ''">
                                                        @csrf
                                                        <button class="flex w-full items-start gap-2.5 px-4 py-2.5 text-left hover:bg-slate-50">
                                                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="isNew(n) ? 'bg-orange-500' : ''"></span>
                                                            <span class="min-w-0">
                                                                <span class="flex items-center gap-1.5"><span class="text-sm font-semibold text-slate-900" x-text="n.title"></span><span x-show="isNew(n)" class="rounded bg-orange-100 px-1.5 text-[11px] font-bold text-orange-800">ใหม่</span></span>
                                                                <span class="block text-xs text-slate-600 line-clamp-2" x-text="n.body"></span>
                                                                <span x-show="n.latest" class="block text-[12px] text-slate-400 truncate" x-text="'ล่าสุด: ' + n.latest"></span>
                                                                <span class="block text-[12px] text-slate-400" x-text="n.at"></span>
                                                            </span>
                                                        </button>
                                                    </form>
                                                </template>
                                                <p x-show="!feed.feed.length" class="px-4 py-3 text-sm text-slate-400">ยังไม่มีความเคลื่อนไหว</p>
                                            </div>
                                        </template>
                                    </div>
                                    <div class="flex items-center justify-between gap-2 border-t border-slate-100 px-4 py-2">
                                        <button type="button" x-show="feed.todoOpen" @click="ackAll()" class="inline-flex min-h-[2.75rem] items-center rounded-md border border-slate-300 px-3 text-xs sm:min-h-0 sm:py-1 font-semibold text-[#0F4C81] hover:bg-slate-50">รับทราบทั้งหมด</button>
                                        <a href="{{ route('notifications.index') }}" class="ml-auto inline-flex min-h-[2.75rem] items-center text-xs font-medium text-blue-600 hover:underline sm:min-h-0">ดูทั้งหมด →</a>
                                    </div>
                                </div>
                            </div>

                            <x-dropdown align="right" width="48">
                                <x-slot name="trigger">
                                    <button class="flex min-h-[2.75rem] items-center gap-2 text-sm font-medium text-slate-600 hover:text-slate-900">
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
        <x-status-report />
        <x-reject-dialog />
        <x-notice-toasts />
        @stack('scripts')
    </body>
</html>
