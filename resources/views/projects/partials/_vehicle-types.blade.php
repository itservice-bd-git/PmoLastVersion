{{-- PM's kinds of vehicle and how many of each (the การจัดส่ง window, the new-project form). The chosen list holds a kind
     once per vehicle (["6 ล้อเครน", "6 ล้อเครน"] = 2 คัน), sent as name[] - ProjectDelivery::normaliseTypes keeps it so.
     $list: a JS expression of the kinds offered; $model: a JS expression of the chosen array (changed in place);
     $name: the field name; $off: a JS expression, true = not sent (another method). --}}
<div x-data="{ carN(t) { return {{ $model }}.filter((x) => x === t).length; },
               carAdd(t) { const a = {{ $model }}; if (a.length >= {{ \App\Models\ProjectDelivery::MAX_VEHICLES }}) return; const i = a.lastIndexOf(t); i < 0 ? a.push(t) : a.splice(i + 1, 0, t); },
               carDrop(t) { const a = {{ $model }}; const i = a.lastIndexOf(t); if (i >= 0) a.splice(i, 1); },
               carClear(t) { const a = {{ $model }}; for (let i = a.length - 1; i >= 0; i--) if (a[i] === t) a.splice(i, 1); } }">
    <div class="mt-1.5 flex flex-wrap gap-2">
        <template x-for="t in {{ $list }}" :key="t">
            <div class="inline-flex h-9 items-center rounded-full border text-sm font-semibold"
                 :class="carN(t) ? 'border-teal-700 bg-teal-50 text-teal-800' : 'border-slate-300 bg-white text-slate-700'">
                <button type="button" @click="carN(t) ? carClear(t) : carAdd(t)" :aria-pressed="carN(t) > 0"
                        class="inline-flex h-full items-center gap-1.5 rounded-full pl-3.5" :class="carN(t) ? 'pr-1.5' : 'pr-3.5'">
                    <span class="flex h-4 w-4 items-center justify-center rounded border" :class="carN(t) ? 'border-teal-700 bg-teal-700 text-white' : 'border-slate-400 bg-white'" aria-hidden="true">
                        <svg x-show="carN(t)" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="m5 12 5 5L20 7"/></svg>
                    </span>
                    <span x-text="t"></span>
                </button>
                {{-- how many of this kind --}}
                <span x-show="carN(t)" class="flex items-center pr-0.5">
                    <button type="button" @click="carDrop(t)" :aria-label="'ลด ' + t + ' 1 คัน'"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-base leading-none hover:bg-teal-100">−</button>
                    <span class="min-w-[2.75rem] text-center tabular-nums" x-text="carN(t) + ' คัน'" aria-live="polite"></span>
                    <button type="button" @click="carAdd(t)" :disabled="{{ $model }}.length >= {{ \App\Models\ProjectDelivery::MAX_VEHICLES }}" :aria-label="'เพิ่ม ' + t + ' 1 คัน'"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-base leading-none hover:bg-teal-100 disabled:opacity-40">+</button>
                </span>
            </div>
        </template>
    </div>
    <p x-show="{{ $model }}.length > 1" class="mt-1 text-xs text-slate-600" x-text="'รวม ' + {{ $model }}.length + ' คัน'"></p>
    <template x-for="(t, i) in {{ $model }}" :key="i + t"><input type="hidden" name="{{ $name }}[]" :value="t" :disabled="{{ $off }}"></template>
</div>
