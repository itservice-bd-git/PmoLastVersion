{{-- One toolbar, sticky under the app header: navigation, เดือน / ไทม์ไลน์ / แดชบอร์ด, search, priority, department, status legend. --}}
<div class="sticky top-16 z-20 bg-white border-b border-slate-200 px-4 py-2.5 space-y-2">
    {{-- one tab per board (main PMO + extra ones from Settings > บอร์ด); switching reloads the page for that board --}}
    <nav x-show="boards.length > 1" class="flex flex-wrap gap-1 text-xs" aria-label="บอร์ด">
        <template x-for="b in boards" :key="b.id">
            <a :href="b.url" class="rounded-full px-3 py-1 font-semibold" :class="b.id === (boardNo ? 'b' + boardNo : 'main') ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'" x-text="b.name"></a>
        </template>
    </nav>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        <div class="inline-flex overflow-hidden rounded-lg border border-slate-300 text-sm">
            <button type="button" @click="shiftMonth(-1)" class="px-2.5 h-8 text-slate-600 hover:bg-slate-50" aria-label="เดือนก่อน">&lsaquo;</button>
            <button type="button" @click="goToday()" class="px-3 h-8 border-x border-slate-300 font-medium text-slate-700 hover:bg-slate-50">วันนี้</button>
            <button type="button" @click="shiftMonth(1)" class="px-2.5 h-8 text-slate-600 hover:bg-slate-50" aria-label="เดือนถัดไป">&rsaquo;</button>
        </div>
        <h3 class="text-base font-bold text-slate-900" x-text="mode === 'dashboard' ? 'แดชบอร์ด' : rangeLabel()"></h3>
        <span x-show="saving > 0" class="text-xs text-slate-400">กำลังบันทึก...</span>
        <span x-show="!saving && savedAt" class="text-xs text-slate-400" x-text="'✓ บันทึกแล้ว ' + savedAt"></span>

        <div class="inline-flex overflow-hidden rounded-lg border border-slate-300 text-sm font-medium">
            <button type="button" @click="mode = 'month'" :class="mode === 'month' ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'" class="px-3 h-8">เดือน</button>
            <button type="button" @click="timeline()" :class="mode === 'timeline' ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'" class="px-3 h-8 border-l border-slate-300">ไทม์ไลน์</button>
            <button type="button" @click="mode = 'dashboard'" :class="mode === 'dashboard' ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'" class="px-3 h-8 border-l border-slate-300">แดชบอร์ด</button>
        </div>

        <div class="relative flex-1 min-w-[12rem] max-w-md">
            <svg class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M21 21l-4.3-4.3"/></svg>
            <input type="search" x-model="search" placeholder="ค้นหางาน / ตู้..." class="w-full h-8 rounded-lg border-slate-300 pl-8 pr-2 text-sm placeholder:text-slate-400">
        </div>

        <div class="inline-flex items-center gap-1.5 text-xs font-medium">
            <button type="button" @click="priority = 'all'" :class="priority === 'all' ? 'border-slate-900 text-slate-900 ring-1 ring-slate-900' : 'border-slate-300 text-slate-600 hover:bg-slate-50'" class="h-7 rounded-md border bg-white px-2.5">ทั้งหมด</button>
            <button type="button" @click="priority = 'urgent'" :class="priority === 'urgent' ? 'border-red-500 ring-1 ring-red-500' : 'border-slate-300 hover:bg-slate-50'" class="h-7 inline-flex items-center gap-1 rounded-md border bg-white px-2.5 text-slate-700"><svg class="w-3.5 h-3.5 text-red-600" viewBox="0 0 24 24" fill="currentColor"><path d="M5 3v18h2v-7h11l-2-4 2-4H7V3H5z"/></svg>ด่วน</button>
            <button type="button" @click="priority = 'high'" :class="priority === 'high' ? 'border-orange-500 ring-1 ring-orange-500' : 'border-slate-300 hover:bg-slate-50'" class="h-7 inline-flex items-center gap-1 rounded-md border bg-white px-2.5 text-slate-700"><svg class="w-3.5 h-3.5 text-orange-500" viewBox="0 0 24 24" fill="currentColor"><path d="M5 3v18h2v-7h11l-2-4 2-4H7V3H5z"/></svg>สูง</button>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        {{-- department view: one chip per project on that department's own due date (a department user's default) --}}
        <select x-model="dept" class="h-8 rounded-lg border-slate-300 py-0 pl-3 pr-8 text-xs font-medium" aria-label="แผนก">
            <option value="">ทุกแผนก</option>
            <template x-for="d in departments" :key="d.id"><option :value="d.id" x-text="d.name" :selected="d.id === dept"></option></template>
        </select>
        <label class="inline-flex items-center gap-1.5 text-xs text-slate-600 cursor-pointer select-none"><input type="checkbox" x-model="hideDone" class="rounded border-slate-300 text-blue-600"> ซ่อนงานที่เสร็จแล้ว</label>
        <button type="button" @click="mineOnly = !mineOnly" :aria-pressed="mineOnly" :class="mineOnly ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-slate-300 text-slate-600 hover:bg-slate-50'" class="h-8 inline-flex items-center gap-1 rounded-lg border px-2.5 text-xs font-medium" title="งานของแผนกฉันที่ยังไม่เสร็จ โครงการที่ฉันเป็น PM หรือมีคนแท็ก / แจ้งเตือนถึงฉัน">👤 ของฉัน</button>
        <button type="button" @click="starOnly = !starOnly" :aria-pressed="starOnly" :class="starOnly ? 'bg-amber-500 border-amber-500 text-white' : 'bg-white border-slate-300 text-slate-600 hover:bg-slate-50'" class="h-8 inline-flex items-center gap-1 rounded-lg border px-3 text-xs font-medium" title="เฉพาะโครงการที่ติดดาว (เก็บไว้ในเบราว์เซอร์นี้)">★ ดาว</button>

        <div class="ml-auto flex items-center gap-3">
            <div class="hidden sm:flex items-center gap-2" aria-label="สีสถานะ">
                <template x-for="s in statuses" :key="s.id"><span class="w-2.5 h-2.5 rounded-full" :style="'background-color:' + s.color" :title="s.name"></span></template>
            </div>
            <a :href="exportUrl()" class="h-8 inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 text-xs font-medium text-slate-600 hover:bg-slate-50" title="ดาวน์โหลดงานของเดือนที่แสดงเป็น CSV">Export CSV</a>
            <template x-if="canEdit">
                <a :href="urls.newProject" class="w-9 h-9 inline-flex items-center justify-center rounded-full bg-blue-600 text-white text-xl leading-none shadow hover:bg-blue-700" title="สร้างโครงการใหม่" aria-label="สร้างโครงการใหม่">+</a>
            </template>
        </div>
    </div>
</div>
