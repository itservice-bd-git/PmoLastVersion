<div class="sidebar-logo-row h-16 flex items-center gap-2 px-5 border-b border-white/10 shrink-0">
    <img src="{{ asset('images/logo.png') }}" alt="Avatar Electric" class="w-9 h-9 object-contain shrink-0">
    <div class="sidebar-label leading-tight">
        <p class="text-sm font-semibold text-white">Avatar Electric</p>
        <p class="text-[11px] text-slate-400">Project &amp; Production Tracking</p>
    </div>
</div>

<nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1">
    @foreach ([
        ['route' => 'dashboard', 'label' => 'แดชบอร์ด', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
        ['route' => 'my-department.index', 'label' => 'งานแผนกของฉัน', 'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
        ['route' => 'projects.index', 'label' => 'โครงการ', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
    ] as $item)
        <a href="{{ route($item['route']) }}" title="{{ $item['label'] }}" aria-label="{{ $item['label'] }}"
           class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition
                  {{ request()->routeIs($item['route'].'*') ? 'bg-blue-600/15 text-blue-200' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $item['icon'] }}" /></svg>
            <span class="sidebar-label">{{ $item['label'] }}</span>
        </a>
    @endforeach

    {{-- Admin/PM only - the same role gate SubtaskAssignmentService enforces server-side. --}}
    @if (auth()->user()?->canDispatchWork())
        <a href="{{ route('assignments.index') }}" title="จ่ายงาน" aria-label="จ่ายงาน"
           class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition
                  {{ request()->routeIs('assignments.*') ? 'bg-blue-600/15 text-blue-200' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" /></svg>
            <span class="sidebar-label">จ่ายงาน</span>
        </a>
    @endif

    <a href="{{ route('cabinets.index') }}" title="ตู้ไฟฟ้า" aria-label="ตู้ไฟฟ้า"
       class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition
              {{ request()->routeIs('cabinets.*') ? 'bg-blue-600/15 text-blue-200' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 3h14a1 1 0 011 1v16a1 1 0 01-1 1H5a1 1 0 01-1-1V4a1 1 0 011-1zM8 7h8M8 11h8M8 15h4" /></svg>
        <span class="sidebar-label">ตู้ไฟฟ้า</span>
    </a>

    <a href="{{ route('cabinet-templates.index') }}" title="เทมเพลต" aria-label="เทมเพลต"
       class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition
              {{ request()->routeIs('cabinet-templates.*') ? 'bg-blue-600/15 text-blue-200' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6a2 2 0 012-2h12a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h4a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" /></svg>
        <span class="sidebar-label">เทมเพลต</span>
    </a>

    @if (auth()->user()?->canManageTrash())
        <a href="{{ route('trash.index') }}" title="ถังขยะ" aria-label="ถังขยะ"
           class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition
                  {{ request()->routeIs('trash.*') ? 'bg-blue-600/15 text-blue-200' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
            <span class="sidebar-label">ถังขยะ</span>
        </a>
    @endif

    <p class="sidebar-label px-3 pt-4 pb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500">ตั้งค่า</p>

    <a href="{{ route('settings.users.index') }}" title="ผู้ใช้งาน" aria-label="ผู้ใช้งาน"
       class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition
              {{ request()->routeIs('settings.users.*') ? 'bg-blue-600/15 text-blue-200' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
        <span class="sidebar-label">ผู้ใช้งาน</span>
    </a>

    <a href="{{ route('settings.departments.index') }}" title="แผนก" aria-label="แผนก"
       class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition
              {{ request()->routeIs('settings.departments.*') ? 'bg-blue-600/15 text-blue-200' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2M19 21H5m0 0H3m8-14h.01M11 11h.01M11 15h.01M7 7h.01M7 11h.01M7 15h.01M15 7h.01M15 11h.01M15 15h.01" /></svg>
        <span class="sidebar-label">แผนก</span>
    </a>

    <a href="{{ route('settings.job-types.index') }}" title="ประเภทงาน" aria-label="ประเภทงาน"
       class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition
              {{ request()->routeIs('settings.job-types.*') ? 'bg-blue-600/15 text-blue-200' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
        <span class="sidebar-label">ประเภทงาน</span>
    </a>
</nav>

<div class="sidebar-label p-4 border-t border-white/10 text-[11px] text-slate-500">
    V1 &middot; Core Architecture
</div>
