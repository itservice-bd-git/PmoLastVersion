@props(['url', 'exportUrl' => null, 'subtitle' => 'ประวัติการเปลี่ยนแปลงทั้งหมด จัดกลุ่มตามโครงสร้างงาน เรียงล่าสุดก่อน'])

{{-- Activity Log tab as a Cabinet > Task > Sub Task > Checklist > Activity tree.
     Pure presentation over the existing activity_logs rows: the server groups them
     (ActivityTreeBuilder) and this renders only the rows under expanded nodes, so a
     project with hundreds of entries stays cheap to draw. --}}
<x-card title="Activity Log" :subtitle="$subtitle">
    <div x-data="activityTree(@js($url), @js($exportUrl))" class="-mx-5 -mb-5" style="--ind: 22px">
        {{-- Filters --}}
        <div class="px-5 pb-3 flex flex-wrap items-center gap-2 text-sm">
            <select x-model="range" @change="applyRange()" class="rounded-lg border-slate-300 text-sm py-1.5">
                <option value="all">ทุกช่วงเวลา</option>
                <option value="today">วันนี้</option>
                <option value="7">7 วันล่าสุด</option>
                <option value="30">30 วันล่าสุด</option>
                <option value="custom">กำหนดเอง</option>
            </select>
            <template x-if="range === 'custom'">
                <span class="flex items-center gap-1">
                    <input type="date" x-model="f.from" @change="load()" class="rounded-lg border-slate-300 text-sm py-1.5">
                    <span class="text-slate-400">→</span>
                    <input type="date" x-model="f.to" @change="load()" class="rounded-lg border-slate-300 text-sm py-1.5">
                </span>
            </template>
            <select x-model="f.category" @change="load()" class="rounded-lg border-slate-300 text-sm py-1.5">
                <option value="">ประเภทกิจกรรม: ทั้งหมด</option>
                <option value="create">สร้าง</option>
                <option value="edit">แก้ไข</option>
                <option value="status">เปลี่ยนสถานะ</option>
                <option value="assignment">Assignment</option>
                <option value="checklist">Checklist</option>
                <option value="attachment">ไฟล์แนบ</option>
                <option value="delete">ลบ</option>
            </select>
            <select x-model="f.entity" @change="load()" class="rounded-lg border-slate-300 text-sm py-1.5">
                <option value="">Entity: ทั้งหมด</option>
                <option value="project">Project</option>
                <option value="cabinet">Cabinet</option>
                <option value="task">Task</option>
                <option value="subtask">Sub Task</option>
                <option value="checklist">Checklist</option>
            </select>
            <select x-model="f.user_id" @change="load()" class="rounded-lg border-slate-300 text-sm py-1.5">
                <option value="">ผู้ดำเนินการ: ทั้งหมด</option>
                <template x-for="u in users" :key="u.id"><option :value="u.id" x-text="u.name"></option></template>
            </select>
            <input type="search" x-model="f.q" @input.debounce.350ms="load()" placeholder="ค้นหา..." class="rounded-lg border-slate-300 text-sm py-1.5 w-full sm:w-52">
            <select x-model="f.sort" @change="load()" class="rounded-lg border-slate-300 text-sm py-1.5">
                <option value="desc">ล่าสุดก่อน</option>
                <option value="asc">เก่าสุดก่อน</option>
            </select>
            <span class="ml-auto flex items-center gap-3 text-xs">
                <button type="button" x-show="exportUrl" @click="exportCsv()" class="text-blue-600 hover:underline">Export CSV</button>
                <span class="text-slate-300">|</span>
                <button type="button" @click="expandAll()" class="text-blue-600 hover:underline">ขยายทั้งหมด</button>
                <span class="text-slate-300">|</span>
                <button type="button" @click="collapseAll()" class="text-blue-600 hover:underline">ยุบทั้งหมด</button>
            </span>
        </div>

        {{-- Column header (desktop) --}}
        <div class="hidden md:grid grid-cols-[minmax(0,1fr)_110px_170px_minmax(0,34%)] gap-3 px-5 py-1.5 border-y border-slate-100 bg-slate-50 text-[12px] font-medium uppercase tracking-wide text-slate-400">
            <span>โครงสร้าง / กิจกรรม <span x-show="total" class="normal-case font-normal" x-text="'· ' + total + ' กิจกรรม'"></span></span>
            <span>เวลา</span><span>ผู้ดำเนินการ</span><span>รายละเอียด</span>
        </div>

        <p x-show="loading" class="px-5 py-6 text-center text-slate-400 text-sm">กำลังโหลด...</p>
        <p x-show="error" x-text="error" class="px-5 py-6 text-center text-red-500 text-sm"></p>
        <p x-show="!loading && !error && nodes.length === 0" x-cloak class="px-5 py-6 text-center text-slate-400 text-sm"
           x-text="hasFilter() ? 'ไม่พบกิจกรรมตามเงื่อนไขที่เลือก' : 'ยังไม่มีประวัติการเปลี่ยนแปลง'"></p>

        <div class="divide-y divide-slate-50 overflow-x-hidden" x-show="!loading">
            <template x-for="r in rows()" :key="r.id">
                <div class="relative">
                    {{-- tree guide lines: one subtle vertical rule per ancestor level --}}
                    <template x-for="d in r.depth" :key="d">
                        <span class="absolute top-0 bottom-0 border-l border-slate-200" :style="'left:calc(var(--ind) * ' + (d - 1) + ' + 21px)'"></span>
                    </template>

                    {{-- node row --}}
                    <template x-if="r.node">
                        <button type="button" @click="toggle(r.node.key)"
                                class="relative w-full text-left flex items-center gap-2 pr-5 hover:bg-slate-50"
                                :class="r.node.type === 'cabinet' ? 'min-h-[42px] bg-slate-50/50' : (r.node.type === 'task' ? 'min-h-[40px]' : 'min-h-[36px]')"
                                :style="'padding-left: calc(var(--ind) * ' + r.depth + ' + 20px)'">
                            <svg class="w-3.5 h-3.5 text-slate-400 shrink-0 transition-transform" :class="open[r.node.key] ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="currentColor"><path d="M7 4l6 6-6 6V4z"/></svg>
                            <span class="shrink-0 text-slate-400" x-html="icon(r.node.type)"></span>
                            <span class="text-[12px] text-slate-400 shrink-0 hidden sm:inline" x-text="typeWord(r.node)"></span>
                            <span class="truncate" :class="r.node.type === 'cabinet' ? 'font-semibold text-ae-navy text-sm' : (r.node.type === 'task' ? 'font-medium text-slate-800 text-sm' : 'text-slate-700 text-[13px]')" x-text="r.node.label"></span>
                            <span class="ml-auto shrink-0 flex items-center gap-3">
                                <span class="hidden lg:inline text-[12px] text-slate-400" x-show="!open[r.node.key] && r.node.latest" x-text="'ล่าสุด ' + r.node.latest"></span>
                                <span class="rounded-full bg-slate-100 text-slate-600 text-xs font-medium px-2 py-0.5 tabular-nums" x-text="r.node.count"></span>
                            </span>
                        </button>
                    </template>

                    {{-- activity leaf --}}
                    <template x-if="r.act">
                        <div class="relative md:grid grid-cols-[minmax(0,1fr)_110px_170px_minmax(0,34%)] gap-3 items-start pr-5 py-2 min-h-[38px] text-[13px]"
                             :style="'padding-left: calc(var(--ind) * ' + r.depth + ' + 20px)'">
                            <div class="flex items-start gap-2 min-w-0">
                                <span class="mt-0.5 shrink-0" :class="actionColor(r.act.icon)" x-html="icon(r.act.icon)"></span>
                                <span class="text-slate-800" x-text="r.act.title"></span>
                            </div>
                            <div class="text-xs text-slate-500 tabular-nums mt-0.5 md:mt-0.5 pl-6 md:pl-0" x-text="r.act.at"></div>
                            <div class="flex items-center gap-1.5 text-xs text-slate-600 min-w-0 pl-6 md:pl-0">
                                <span class="w-5 h-5 rounded-full bg-slate-100 text-[11.5px] font-semibold text-slate-500 flex items-center justify-center shrink-0" x-text="r.act.initial"></span>
                                <span class="truncate" x-text="r.act.actor"></span>
                            </div>
                            <div class="text-xs text-slate-500 min-w-0 pl-6 md:pl-0 mt-1 md:mt-0">
                                <template x-for="c in r.act.changes" :key="c.label">
                                    <p class="break-words"><span class="text-slate-400" x-text="c.label + ': '"></span><span class="text-slate-500" x-text="c.old"></span> <span class="text-slate-400">→</span> <span class="text-slate-800 font-medium" x-text="c.new"></span></p>
                                </template>
                                <p x-show="r.act.detail" class="break-words" x-text="r.act.detail"></p>
                            </div>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>
</x-card>
