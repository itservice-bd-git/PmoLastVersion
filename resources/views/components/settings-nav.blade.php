{{-- The one navigation every Settings page shares (Avatar Planning's settings window, as separate Laravel pages).
     Everyone gets "การแจ้งเตือน" and the templates; the rest is admin-only (the routes enforce it, this only hides what you cannot open).
     The pages are grouped by what they are for, and a search box finds one by its name or by what it holds ("ทีม" finds แผนก,
     "รถ" finds รถขนส่ง). Enter opens the first match, ↓ moves into the list. Beside the page on a wide screen (x-settings-layout,
     xl); narrower, the groups fold under the current page's name and typing opens them. A short menu (a member's two pages)
     has no search box. --}}
@php
    $u = auth()->user();
    // [group, [label, url, active route pattern, words it is also found by]]
    $items = array_filter([
        $u?->isAdmin() ? ['ผู้ใช้และสิทธิ์', 'ผู้ใช้งาน', route('settings.users.index'), 'settings.users.*', 'user บัญชี รหัสผ่าน login ทีม บทบาท role'] : null,
        $u?->isAdmin() ? ['ผู้ใช้และสิทธิ์', 'แผนก', route('settings.departments.index'), 'settings.departments.*', 'department ทีม team ฝ่าย'] : null,
        $u?->isAdmin() ? ['ผู้ใช้และสิทธิ์', 'สิทธิ์การใช้งาน', route('settings.permissions.edit'), 'settings.permissions.*', 'permission สิทธิ์ เห็นทุกโครงการ ดูได้อย่างเดียว'] : null,
        $u?->isAdmin() ? ['งานและเทมเพลต', 'ประเภทงาน', route('settings.job-types.index'), 'settings.job-types.*', 'job type ชนิดงาน'] : null,
        $u?->allows('page.templates') ? ['งานและเทมเพลต', 'เทมเพลตตู้/เช็คลิสต์', route('cabinet-templates.index'), 'cabinet-templates.*', 'template ตู้ checklist เช็คลิสต์'] : null,   // Settings > สิทธิ์การใช้งาน
        $u?->isAdmin() ? ['งานและเทมเพลต', 'ฟิลด์เพิ่มเติม', route('settings.fields.index'), 'settings.fields.*', 'field ช่องข้อมูล'] : null,
        $u?->isAdmin() ? ['งานและเทมเพลต', 'สาเหตุการเลื่อน', route('settings.date-reasons.index'), 'settings.date-reasons.*', 'เลื่อนวัน แก้วัน reason'] : null,
        $u?->isAdmin() ? ['สถานะและกฎ', 'สถานะงาน', route('settings.statuses.edit'), 'settings.statuses.*', 'status สี FAT จัดส่ง'] : null,
        $u?->isAdmin() ? ['สถานะและกฎ', 'สีสถานะ Checklist', route('settings.checklist-statuses.index'), 'settings.checklist-statuses.*', 'สี ป้าย checklist status'] : null,
        $u?->isAdmin() ? ['สถานะและกฎ', 'กฎสถานะ', route('settings.status-rules.index'), 'settings.status-rules.*', 'rule สถานะอัตโนมัติ'] : null,
        $u?->isAdmin() ? ['สถานะและกฎ', 'กฎการทำงาน', route('settings.rules.edit'), 'settings.rules.*', 'rule เสร็จงาน รับงาน ปิดโครงการ'] : null,
        $u?->isAdmin() ? ['สถานะและกฎ', 'งานอัตโนมัติ', route('settings.automation.index'), 'settings.automation.*', 'อัตโนมัติ automation กำหนดวัน'] : null,
        $u?->isAdmin() ? ['Planning', 'บอร์ด', route('settings.boards.index'), 'settings.boards.*', 'board planning'] : null,
        $u?->isAdmin() ? ['Planning', 'แท็ก', route('settings.labels.index'), 'settings.labels.*', 'label tag ป้าย'] : null,
        $u?->isAdmin() ? ['การแจ้งเตือน', 'แจ้งเตือน Checklist', route('settings.notice-rules.index'), 'settings.notice-rules.*', 'notice แจ้งเตือน ติ๊ก'] : null,
        ['การแจ้งเตือน', 'การแจ้งเตือน', route('settings.notifications.edit'), 'settings.notifications.*', 'notification แจ้งเตือน push'],
        $u?->allows('edit.delivery') ? ['อื่น ๆ', 'รถขนส่ง', route('settings.vehicles.index'), 'settings.vehicles.*', 'รถ คนขับ ขนส่ง vehicle ไม่รับงาน เปิดบิล'] : null,   // the delivery department keeps its vehicles
        $u?->canManageTrash() ? ['อื่น ๆ', 'ถังขยะ', route('trash.index'), 'trash.*', 'trash ลบ กู้คืน'] : null,
    ]);
    // each group's icon (outline, 24 grid): the heading reads as the main menu, the pages under it as its sub-menu
    $groupIcons = [
        'ผู้ใช้และสิทธิ์' => 'M16 19v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M9 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm13 9v-1a4 4 0 0 0-3-3.87M16 4.13a3 3 0 0 1 0 5.74',
        'งานและเทมเพลต' => 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-6 9 2 2 4-4',
        'สถานะและกฎ' => 'M4 21V4m0 0h11l-1.5 4L15 12H4',
        'Planning' => 'M8 3v3m8-3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z',
        'การแจ้งเตือน' => 'M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0v1a3 3 0 1 1-6 0v-1m6 0H9',
        'อื่น ๆ' => 'M5 12h.01M12 12h.01M19 12h.01',
    ];
    $groups = collect($items)->groupBy(0);
    $current = collect($items)->first(fn ($i) => request()->routeIs($i[3]))[1] ?? 'ตั้งค่า';
    $findable = count($items) > 6;
@endphp
<nav aria-label="ตั้งค่า"
     class="mb-5 rounded-xl border border-slate-200 bg-white shadow-sm xl:sticky xl:top-[5.5rem] xl:mb-0 xl:flex xl:max-h-[calc(100vh-7rem)] xl:flex-col"
     x-data="{ q: '', open: false,
               words: el => (el.dataset.find || '').toLowerCase(),
               hit(el) { const q = this.q.trim().toLowerCase(); return !q || q.split(/\s+/).every(w => this.words(el).includes(w)); },
               shown() { return [...this.$root.querySelectorAll('[data-find]')].filter(el => this.hit(el)); },
               first(el) { return this.q.trim() !== '' && this.shown()[0] === el; },
               go() { const a = this.shown()[0]; if (this.q.trim() && a) location.href = a.href; },
               into() { const a = this.shown()[0]; if (a) { this.open = true; this.$nextTick(() => a.focus()); } } }">
    <div class="flex items-center gap-2 p-2 xl:flex-col xl:items-stretch xl:border-b xl:border-slate-100 {{ $findable ? '' : 'xl:hidden' }}">
        @if ($findable)
            <div class="relative min-w-0 flex-1 xl:flex-none">
                <label for="settings-find" class="sr-only">ค้นหาการตั้งค่า</label>
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
                <input id="settings-find" type="search" x-model="q" autocomplete="off" enterkeyhint="go" aria-controls="settings-groups"
                       @input="open = q.trim() !== ''" @keydown.enter.prevent="go()" @keydown.down.prevent="into()" @keydown.escape="q = ''; open = false"
                       placeholder="ค้นหาการตั้งค่า"
                       class="h-11 w-full rounded-lg border-slate-300 pl-9 text-sm placeholder:text-slate-500 focus:border-blue-500 focus:ring-blue-500">
            </div>
        @endif
        {{-- narrower than xl: the groups fold under the page you are on --}}
        <button type="button" @click="open = !open" :aria-expanded="open.toString()" aria-controls="settings-groups"
                class="inline-flex h-11 shrink-0 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 xl:hidden {{ $findable ? '' : 'flex-1' }}">
            <svg class="h-4 w-4 text-slate-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h10"/></svg>
            <span class="sr-only">เมนูตั้งค่า · หน้านี้:</span>
            <span class="min-w-0 truncate text-left {{ $findable ? 'max-w-[10rem]' : 'flex-1' }}">{{ $current }}</span>
            <svg class="h-4 w-4 text-slate-500 transition-transform duration-150" :class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
        </button>
    </div>
    <div id="settings-groups" :class="open && '!flex'"
         class="hidden flex-col gap-4 border-t border-slate-100 p-3 sm:flex-row sm:flex-wrap sm:gap-x-8 sm:gap-y-4 xl:flex xl:min-h-0 xl:flex-col xl:flex-nowrap xl:gap-4 xl:overflow-y-auto xl:overscroll-contain xl:border-t-0">
        @foreach ($groups as $group => $list)
            <div role="group" aria-labelledby="settings-group-{{ $loop->index }}" class="flex min-w-0 flex-col sm:w-44 xl:w-auto"
                 x-show="[...$el.querySelectorAll('[data-find]')].some(a => hit(a))">
                <p id="settings-group-{{ $loop->index }}" class="flex items-center gap-2 px-2 pb-1 text-sm font-bold text-slate-900">
                    <svg class="h-4 w-4 shrink-0 text-slate-500" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $groupIcons[$group] ?? $groupIcons['อื่น ๆ'] }}"/></svg>
                    {{ $group }}
                </p>
                {{-- the pages of the group: indented under it, on a guide line --}}
                <div class="ml-4 flex flex-col gap-px border-l-2 border-slate-200 pl-2">
                @foreach ($list as [, $label, $url, $pattern, $words])
                    @php $on = request()->routeIs($pattern); @endphp
                    <a href="{{ $url }}" data-find="{{ $label }} {{ $group }} {{ $words }}" x-show="hit($el)" @if ($on) aria-current="page" @endif
                       :class="first($el) && {{ $on ? 'false' : 'true' }} ? 'bg-blue-50 text-blue-800 ring-1 ring-inset ring-blue-200' : ''"
                       class="flex min-h-[2.25rem] w-full items-center rounded-lg px-3 xl:min-h-8 text-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 {{ $on ? 'bg-slate-800 font-semibold text-white' : 'font-normal text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">{{ $label }}</a>
                @endforeach
                </div>
            </div>
        @endforeach
        <p x-show="q.trim() && shown().length" x-cloak class="w-full px-3 text-xs text-slate-500">กด Enter เพื่อเปิดรายการแรก</p>
        <p x-show="q.trim() && !shown().length" x-cloak class="w-full px-3 py-1 text-sm text-slate-600" role="status">
            ไม่พบการตั้งค่าที่ตรงกับ “<span x-text="q.trim()"></span>” · ลองคำอื่น เช่น ทีม, รถ, สถานะ
        </p>
    </div>
</nav>
