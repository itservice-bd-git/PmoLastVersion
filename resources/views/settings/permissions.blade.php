<x-app-layout>
    <x-slot name="header"><h1 class="text-lg font-semibold text-slate-900">ตั้งค่า</h1></x-slot>
    <x-settings-layout space="space-y-4">

        {{-- Two views of one table (App\Support\Access; resources/js/permissions.js): "ตามแผนก" sets one department with
             switches, "ตารางรวม" compares them all - a row per permission, a column per department (+ no department).
             Admin / PM may everything and are not in it. The table's checkboxes are what the form sends. --}}
        <form method="POST" action="{{ route('settings.permissions.update') }}" @submit="saving = true"
              x-data="permissionTable({ table: @js($table), columns: @js($columns->values()), implied: @js(\App\Support\Access::IMPLIED),
                                        needs: @js(\App\Support\Access::NEEDS), labels: @js(collect($permissions)->map(fn ($p) => $p['label'])) })">
            @csrf @method('PUT')
            <x-card title="สิทธิ์การใช้งานตามแผนก">
                <div role="tablist" aria-label="มุมมอง" class="-mt-1 mb-5 inline-flex rounded-lg bg-slate-100 p-1">
                    @foreach (['dept' => 'ตามแผนก', 'table' => 'ตารางรวม'] as $v => $name)
                        <button type="button" role="tab" id="perm-tab-{{ $v }}" aria-controls="perm-view-{{ $v }}"
                                :aria-selected="(view === '{{ $v }}').toString()" @click="view = '{{ $v }}'"
                                :class="view === '{{ $v }}' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'"
                                class="h-9 rounded-md px-4 text-sm font-semibold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">{{ $name }}</button>
                    @endforeach
                </div>

                {{-- ตามแผนก: pick a department, its permissions as switches with their full explanation --}}
                <div id="perm-view-dept" role="tabpanel" aria-labelledby="perm-tab-dept" x-show="view === 'dept'"
                     class="md:grid md:grid-cols-[13rem_minmax(0,1fr)] md:items-start md:gap-6">
                    <div class="mb-4 md:hidden">
                        <label for="perm-col" class="mb-1 block text-xs font-semibold text-slate-600">แผนก</label>
                        <select id="perm-col" x-model="col" class="h-11 w-full rounded-lg border-slate-300 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @foreach ($columns as $c)
                                <option value="{{ $c['key'] }}">{{ $c['name'] }} ({{ $c['people'] }} คน)</option>
                            @endforeach
                        </select>
                    </div>
                    <nav aria-label="เลือกแผนก" class="hidden md:sticky md:top-[5.5rem] md:flex md:max-h-[calc(100vh-8rem)] md:flex-col md:gap-px md:overflow-y-auto">
                        @foreach ($columns as $c)
                            <button type="button" @click="col = '{{ $c['key'] }}'" :aria-current="col === '{{ $c['key'] }}' && 'true'"
                                    :class="col === '{{ $c['key'] }}' ? 'bg-slate-800 text-white' : 'text-slate-700 hover:bg-slate-100'"
                                    class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-semibold">{{ $c['name'] }}</span>
                                    <span class="block text-xs tabular-nums" :class="col === '{{ $c['key'] }}' ? 'text-slate-300' : 'text-slate-500'">{{ $c['people'] }} คน · <span x-text="onIn('{{ $c['key'] }}')"></span>/<span x-text="total()"></span> สิทธิ์</span>
                                </span>
                                <span x-show="dirtyIn('{{ $c['key'] }}')" x-cloak class="h-2 w-2 shrink-0 rounded-full bg-amber-500" title="มีการแก้ไขที่ยังไม่บันทึก"></span>
                            </button>
                        @endforeach
                    </nav>

                    <div class="min-w-0">
                        <div class="mb-3 flex flex-wrap items-end justify-between gap-x-4 gap-y-3 border-b border-slate-100 pb-4">
                            <div class="min-w-0">
                                <h4 class="text-lg font-bold text-slate-900" x-text="colName(col)"></h4>
                                <p class="text-sm text-slate-600 tabular-nums">เปิด <span x-text="onIn(col)"></span> จาก <span x-text="total()"></span> สิทธิ์</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <label for="perm-copy" class="sr-only">คัดลอกสิทธิ์จากแผนก</label>
                                <select id="perm-copy" x-model="copyFrom" class="h-9 max-w-[11rem] rounded-lg border-slate-300 text-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="">คัดลอกจากแผนก…</option>
                                    @foreach ($columns as $c)
                                        <option value="{{ $c['key'] }}" x-show="col !== '{{ $c['key'] }}'">{{ $c['name'] }}</option>
                                    @endforeach
                                </select>
                                <button type="button" @click="copyCol()" :disabled="!copyFrom"
                                        class="h-9 rounded-lg border border-slate-300 px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">คัดลอก</button>
                            </div>
                        </div>

                        @foreach ($groups as $group => $groupLabel)
                            <section class="mb-5 last:mb-0" aria-labelledby="perm-group-{{ $group }}">
                                <h5 id="perm-group-{{ $group }}" class="mb-1 px-3 text-sm font-bold text-slate-900">{{ $groupLabel }}</h5>
                                <div class="divide-y divide-slate-100">
                                    @foreach ($permissions as $key => $p)
                                        @continue ($p['group'] !== $group)
                                        <label class="flex cursor-pointer items-start gap-4 rounded-lg px-3 py-3 hover:bg-slate-50"
                                               :class="changed(col, '{{ $key }}') && 'bg-amber-50 hover:bg-amber-50'">
                                            <span class="min-w-0 flex-1">
                                                <span class="flex flex-wrap items-center gap-x-2 font-medium text-slate-800">
                                                    {{ $p['label'] }}
                                                    <span x-show="changed(col, '{{ $key }}')" x-cloak class="rounded bg-amber-100 px-1.5 text-xs font-semibold text-amber-800">แก้แล้ว</span>
                                                </span>
                                                @if ($p['hint'])
                                                    <span class="mt-0.5 block text-sm text-slate-600">{{ $p['hint'] }}</span>
                                                @endif
                                                <span x-show="via(col, '{{ $key }}')" x-cloak class="mt-1 block text-sm text-blue-700">ได้อยู่แล้วจาก “<span x-text="via(col, '{{ $key }}')"></span>”</span>
                                                <span x-show="lacks(col, '{{ $key }}')" x-cloak class="mt-1 block text-sm font-medium text-amber-800">ยังใช้ไม่ได้: ต้องเปิด “<span x-text="lacks(col, '{{ $key }}')"></span>” ด้วย</span>
                                            </span>
                                            <span class="relative mt-0.5 inline-flex shrink-0">
                                                <input type="checkbox" class="peer sr-only" :checked="has(col, '{{ $key }}')" :disabled="!!via(col, '{{ $key }}')"
                                                       @change="set(col, '{{ $key }}', $event.target.checked)" :aria-label="colName(col) + ': {{ $p['label'] }}'">
                                                <span aria-hidden="true"
                                                      class="h-6 w-11 rounded-full bg-slate-300 transition-colors after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow-sm after:transition-transform peer-checked:bg-blue-600 peer-checked:after:translate-x-5 peer-focus-visible:ring-2 peer-focus-visible:ring-blue-500 peer-focus-visible:ring-offset-2 peer-disabled:opacity-60"></span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>
                </div>

                {{-- ตารางรวม: the header and the permission column stay in view while the table scrolls; a header box sets a whole department --}}
                <div id="perm-view-table" role="tabpanel" aria-labelledby="perm-tab-table" x-show="view === 'table'" x-cloak>
                    <div class="-mx-5 max-h-[70vh] overflow-auto overscroll-contain border-y border-slate-200">
                        <table class="w-full min-w-[640px] border-separate border-spacing-0 text-sm">
                            <thead>
                                <tr class="text-left text-xs text-slate-600">
                                    <th class="sticky left-0 top-0 z-30 min-w-[12rem] border-b border-r border-slate-200 bg-white px-4 py-2 font-semibold sm:w-64">สิทธิ์</th>
                                    @foreach ($columns as $c)
                                        <th class="sticky top-0 z-20 min-w-[5.5rem] border-b border-slate-200 bg-white px-1.5 py-2 text-center align-bottom font-semibold">
                                            <span class="block {{ $c['key'] === 'none' ? 'text-slate-500' : 'text-slate-800' }}">{{ $c['name'] }}</span>
                                            <span class="block font-normal text-slate-500">{{ $c['people'] }} คน</span>
                                            <input type="checkbox" class="mt-1.5 h-4 w-4 rounded border-slate-300 text-blue-600"
                                                   :checked="allOn('{{ $c['key'] }}')" :indeterminate="someOn('{{ $c['key'] }}')"
                                                   @change="setAll('{{ $c['key'] }}', $event.target.checked)"
                                                   title="ติ๊ก / ไม่ติ๊กทั้งแผนก" aria-label="{{ $c['name'] }}: ทุกสิทธิ์">
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            @foreach ($groups as $group => $groupLabel)
                                <tbody>
                                    <tr><th colspan="{{ count($columns) + 1 }}" class="border-b border-slate-200 bg-slate-50 px-4 py-1.5 text-left text-xs font-bold text-slate-700">
                                        <span class="sticky left-4">{{ $groupLabel }}</span></th></tr>
                                    @foreach ($permissions as $key => $p)
                                        @continue ($p['group'] !== $group)
                                        <tr class="group">
                                            <td class="sticky left-0 z-10 border-b border-r border-slate-200 bg-white px-4 py-2 group-hover:bg-blue-50" title="{{ $p['hint'] }}">
                                                <p class="font-medium text-slate-800">{{ $p['label'] }}</p>
                                                @if ($p['hint'])<p class="line-clamp-1 text-xs text-slate-500">{{ $p['hint'] }}</p>@endif
                                            </td>
                                            @foreach ($columns as $c)
                                                <td class="border-b border-slate-100 px-2 py-2 text-center group-hover:bg-blue-50/50"
                                                    :class="changed('{{ $c['key'] }}', '{{ $key }}') && '!bg-amber-100'">
                                                    <input type="checkbox" name="perm[{{ $c['key'] }}][{{ $key }}]" value="1" @checked($table[$c['key']][$key])
                                                           x-model="v['{{ $c['key'] }}']['{{ $key }}']"
                                                           class="h-4 w-4 rounded border-slate-300 text-blue-600" aria-label="{{ $c['name'] }}: {{ $p['label'] }}">
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            @endforeach
                        </table>
                    </div>
                    <p class="mt-2 text-xs text-slate-500">ช่องสีเหลือง = แก้แล้ว ยังไม่บันทึก · ช่องบนหัวคอลัมน์ = ติ๊กทั้งแผนก</p>
                </div>

                <div class="mt-6 border-t border-slate-100 pt-4">
                    <p><button type="submit" form="reset-permissions" class="text-sm font-medium text-slate-600 underline-offset-2 hover:text-slate-900 hover:underline">กลับไปใช้ค่าเริ่มต้นทั้งหมด</button></p>
                </div>
            </x-card>

            {{-- stays at the bottom of the screen: how many boxes changed, undo, save --}}
            <div class="sticky bottom-3 z-30 mt-4 flex flex-wrap items-center gap-3 rounded-xl border bg-white px-4 py-3 shadow-[0_8px_24px_-6px_rgba(15,23,42,0.18)]"
                 :class="dirty() ? 'border-amber-300' : 'border-slate-200'">
                <p class="min-w-0 flex-1 text-sm" role="status" aria-live="polite">
                    <span x-show="dirty()" x-cloak class="inline-flex items-center gap-2 font-semibold text-amber-800">
                        <span class="h-2 w-2 rounded-full bg-amber-500" aria-hidden="true"></span>
                        <span>แก้ไข <span x-text="dirty()" class="tabular-nums"></span> ช่อง · ยังไม่บันทึก</span>
                    </span>
                    <span x-show="!dirty()" class="text-slate-600">ยังไม่มีการแก้ไข</span>
                </p>
                <button type="button" x-show="dirty()" x-cloak @click="undo()"
                        class="h-10 rounded-lg px-3 text-sm font-semibold text-slate-700 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">ยกเลิกการแก้ไข</button>
                <button :disabled="!dirty()"
                        class="h-10 rounded-lg bg-blue-600 px-5 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">บันทึก</button>
            </div>
        </form>
        <form id="reset-permissions" method="POST" action="{{ route('settings.permissions.reset') }}" onsubmit="return confirm('กลับไปใช้สิทธิ์ค่าเริ่มต้นทั้งหมด? (ทุกแผนกจะเห็นเฉพาะโครงการที่มีงาน)')">@csrf @method('DELETE')</form>
    </x-settings-layout>
</x-app-layout>
