{{-- แดชบอร์ด: tiles, unfinished work by due date, progress per department (checklist), and the project table. Follows the department filter. --}}
<div x-show="mode === 'dashboard'" x-cloak class="p-4 sm:p-5 space-y-5">
    <div class="flex flex-wrap items-center gap-2 text-xs">
        <span class="text-slate-500">กรองแผนก:</span>
        <button type="button" @click="dept = ''" :class="dept === '' ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-slate-300 text-slate-600 hover:bg-slate-50'" class="rounded-full border px-3 py-1 font-medium">ทุกแผนก</button>
        <template x-for="d in departments" :key="d.id">
            <button type="button" @click="dept = d.id" :class="dept === d.id ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-slate-300 text-slate-600 hover:bg-slate-50'" class="rounded-full border px-3 py-1 font-medium" x-text="d.name"></button>
        </template>
    </div>

    {{-- `d` = the computed figures (one-element list = a local alias, recomputed whenever the data or a filter changes) --}}
    <template x-for="d in (mode === 'dashboard' ? [dash()] : [])" :key="'dash'">
        <div class="space-y-5">
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-2xl font-bold text-slate-900" x-text="d.total"></p><p class="text-xs text-slate-500 mt-1">โปรเจคทั้งหมด</p></div>
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-2xl font-bold text-emerald-600" x-text="d.completed"></p><p class="text-xs text-slate-500 mt-1">เสร็จแล้ว</p></div>
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-2xl font-bold text-blue-600" x-text="d.doing"></p><p class="text-xs text-slate-500 mt-1">กำลังทำ</p></div>
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-2xl font-bold text-red-600" x-text="d.late"></p><p class="text-xs text-slate-500 mt-1">เลยกำหนด</p></div>
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-2xl font-bold text-slate-900" x-text="d.checkPct + '%'"></p><p class="text-xs text-slate-500 mt-1" x-text="'เช็คลิสต์รวม (' + d.checkDone + '/' + d.checkTotal + ')'"></p></div>
            </div>

            <div>
                <h4 class="text-sm font-bold text-slate-900">ภาระงานรายสัปดาห์ <span class="font-normal text-xs text-slate-400">(งานที่ยังไม่เสร็จ ตามวันครบกำหนด)</span></h4>
                <div class="mt-2 rounded-xl border border-slate-200 p-4">
                    <div class="flex items-end gap-4 h-44" role="img" aria-label="จำนวนงานที่ยังไม่เสร็จแยกตามช่วงวันครบกำหนด">
                        <template x-for="b in d.bucket" :key="b.label">
                            <div class="flex-1 flex flex-col items-center justify-end h-full" :title="b.label + ': ' + b.n + ' งาน'">
                                <span class="text-xs font-semibold tabular-nums" :class="b.late && b.n > 0 ? 'text-red-700' : 'text-slate-700'" x-text="b.n"></span>
                                <div class="w-full max-w-[56px] rounded-t" :class="[b.late ? 'bg-red-600' : 'bg-blue-600', b.n === 0 ? 'opacity-30' : '']" :style="'height:' + (b.n === 0 ? 3 : Math.max(6, Math.round(b.n / d.bucketMax * 100))) + '%'"></div>
                                <span class="mt-1.5 text-[11px] text-center" :class="b.late ? 'text-red-700 font-medium' : 'text-slate-500'" x-text="b.label"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <div>
                <h4 class="text-sm font-bold text-slate-900">ความคืบหน้าแต่ละแผนก</h4>
                <div class="mt-2 rounded-xl border border-slate-200 divide-y divide-slate-100">
                    <template x-for="r in d.depts" :key="r.dp.id">
                        <div class="grid grid-cols-[minmax(7rem,12rem)_1fr_auto] items-center gap-4 px-4 py-2.5">
                            <span class="text-sm font-medium text-slate-800 truncate" x-text="r.dp.name"></span>
                            <div class="h-2 rounded-full bg-slate-100 overflow-hidden"><div class="h-2 rounded-full" :style="'width:' + r.pct + '%;background-color:' + r.dp.color"></div></div>
                            <span class="text-xs text-slate-500 whitespace-nowrap tabular-nums"><span x-text="r.done + '/' + r.total + ' (' + r.pct + '%)'"></span> · เสร็จ <span x-text="r.stagesDone + '/' + r.stages"></span><template x-if="r.late > 0"><span> · <b class="text-red-600" x-text="'เลย ' + r.late"></b></span></template></span>
                        </div>
                    </template>
                    <p x-show="d.depts.length === 0" class="px-4 py-6 text-center text-sm text-slate-400">ยังไม่มีข้อมูลแผนก</p>
                </div>
            </div>

            <div>
                <h4 class="text-sm font-bold text-slate-900" x-text="'โปรเจคทั้งหมด (' + d.rows.length + ')'"></h4>
                <div class="mt-2 overflow-x-auto rounded-xl border border-slate-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-xs text-slate-500"><tr>
                            <th class="px-4 py-2 text-left font-semibold">โปรเจค</th><th class="px-4 py-2 text-left font-semibold">วันครบกำหนด</th><th class="px-4 py-2 text-left font-semibold">สถานะ</th>
                            <th class="px-4 py-2 text-left font-semibold">แผนก</th><th class="px-4 py-2 text-left font-semibold">เช็คลิสต์</th><th class="px-4 py-2 text-right font-semibold">ตู้</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="t in d.rows" :key="t.id">
                                <tr class="hover:bg-slate-50 cursor-pointer" @click="openModal(t.id)">
                                    <td class="px-4 py-2.5"><span class="font-semibold text-blue-600" x-text="t.so"></span><p class="text-xs text-slate-500" x-text="t.title"></p></td>
                                    <td class="px-4 py-2.5 whitespace-nowrap"><span x-text="fmtBE(dueFor(t))"></span><p x-show="overdueFor(t)" class="text-[11px] font-semibold text-red-600" x-text="'เลย ' + Math.abs(daysFromToday(dueFor(t))) + ' วัน'"></p></td>
                                    <td class="px-4 py-2.5"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :style="'background-color:' + statusColor(t) + '26;color:' + statusColor(t)" x-text="statusOf(t).name"></span></td>
                                    <td class="px-4 py-2.5 text-xs text-slate-600"><template x-for="s in t.deptStages" :key="s.deptId"><span class="inline-flex items-center gap-1 mr-2"><span class="w-2 h-2 rounded-full" :style="'background-color:' + deptOf(s.deptId).color"></span><span :class="s.done ? 'line-through text-slate-400' : ''" x-text="deptOf(s.deptId).name"></span></span></template></td>
                                    <td class="px-4 py-2.5 whitespace-nowrap"><div class="flex items-center gap-2 w-32"><div class="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden"><div class="h-1.5 rounded-full bg-emerald-500" :style="'width:' + progress(t, dept || null).pct + '%'"></div></div><span class="text-xs text-slate-500 tabular-nums" x-text="progress(t, dept || null).pct + '%'"></span></div></td>
                                    <td class="px-4 py-2.5 text-right font-semibold tabular-nums" x-text="t.subtasks.length"></td>
                                </tr>
                            </template>
                            <tr x-show="d.rows.length === 0"><td colspan="6" class="px-4 py-8 text-center text-slate-400">ยังไม่มีโปรเจคตามเงื่อนไขที่เลือก</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </template>
</div>
