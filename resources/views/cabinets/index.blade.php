<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">ตู้ไฟฟ้า</h2>
    </x-slot>

    <div class="max-w-7xl mx-auto space-y-4">
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <label class="relative w-full sm:w-72">
                <span class="sr-only">ค้นหาตู้</span>
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ค้นหาเลข MO / ชื่อตู้"
                       class="h-10 w-full rounded-lg border-slate-300 pl-9 text-sm" />
            </label>
            <select name="status" class="h-10 rounded-lg border-slate-300 text-sm" aria-label="สถานะ">
                <option value="">ทุกสถานะ</option>
                @foreach ($statuses as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <button class="h-10 rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">ค้นหา</button>
        </form>

        <x-card class="!p-0">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        {{-- as the projects list: Thai headers, the first column kept in view when it scrolls sideways --}}
                        <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs font-semibold text-slate-500">
                            <th class="sticky left-0 z-[1] whitespace-nowrap bg-slate-50 px-5 py-2.5">เลข MO</th>
                            <th class="whitespace-nowrap px-3 py-2.5">ชื่อตู้</th>
                            <th class="whitespace-nowrap px-3 py-2.5">โครงการ</th>
                            <th class="whitespace-nowrap px-3 py-2.5">กำหนดเสร็จ</th>
                            <th class="whitespace-nowrap px-3 py-2.5">สถานะ</th>
                            <th class="w-36 min-w-[7rem] whitespace-nowrap px-3 py-2.5 pr-5">ความคืบหน้า</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($cabinets as $cabinet)
                            <tr class="group cursor-pointer hover:bg-blue-50/40" onclick="window.location='{{ route('cabinets.show', $cabinet) }}'">
                                <td class="sticky left-0 z-[1] whitespace-nowrap bg-white px-5 py-3 group-hover:bg-[#f6f9fe]">
                                    <a href="{{ route('cabinets.show', $cabinet) }}" class="font-bold tabular-nums text-ae-navy hover:underline">{{ $cabinet->mo_no ?: '-' }}</a>
                                </td>
                                <td class="min-w-[12rem] max-w-[18rem] px-3 py-3">
                                    {{-- a long name: three lines at most (all of it on hover) --}}
                                    <span class="line-clamp-3 break-words font-medium text-slate-800" title="{{ $cabinet->cabinet_name }}">{{ $cabinet->cabinet_name }}</span>
                                    @php $what = collect([$cabinet->cabinet_type, $cabinet->size ? 'ขนาด '.$cabinet->size : null, $cabinet->quantity > 1 ? $cabinet->quantity.' ตู้' : null])->filter()->implode(' · '); @endphp
                                    @if ($what)<span class="block text-xs text-slate-500">{{ $what }}</span>@endif
                                </td>
                                <td class="min-w-[8rem] max-w-[11rem] px-3 py-3">
                                    <span class="block whitespace-nowrap text-slate-700">{{ $cabinet->project->project_no }}</span>
                                    <span class="block truncate text-xs text-slate-500" title="{{ $cabinet->project->project_name }}">{{ $cabinet->project->project_name }}</span>
                                </td>
                                <td class="px-3 py-3 text-slate-600 whitespace-nowrap">
                                    {{ optional($cabinet->due_date)->thaiDate() ?? '-' }}
                                    @if ($cabinet->due_date && $cabinet->status !== \App\Models\Cabinet::STATUS_COMPLETED)
                                        @php $days = (int) today()->diffInDays($cabinet->due_date, false); @endphp
                                        <p class="mt-0.5">
                                            <span class="inline-block text-xs font-medium px-1.5 py-0.5 rounded {{ $days < 0 ? 'bg-red-100 text-red-700' : ($days <= 7 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500') }}">
                                                @if ($days < 0)
                                                    เลยกำหนด {{ abs($days) }} วัน
                                                @elseif ($days === 0)
                                                    ครบกำหนดวันนี้
                                                @else
                                                    เหลือ {{ $days }} วัน
                                                @endif
                                            </span>
                                        </p>
                                        @if ($cabinet->expected_completion_date)
                                            <p class="mt-0.5 text-xs {{ $cabinet->expected_completion_date->gt($cabinet->due_date) ? 'text-red-600' : 'text-slate-400' }}">คาดเสร็จ {{ $cabinet->expected_completion_date->thaiDate() }}</p>
                                        @endif
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-3"><x-status-badge :status="$cabinet->status" /></td>
                                <td class="px-3 py-3 pr-5">
                                    <div class="flex items-center gap-2">
                                        <x-progress-bar :value="$cabinet->progress" size="sm" class="flex-1" />
                                        <span class="w-9 text-right text-xs font-bold tabular-nums text-slate-700">{{ (int) $cabinet->progress }}%</span>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-6 text-center text-slate-400">ไม่พบตู้ไฟฟ้า</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        {{ $cabinets->links() }}
    </div>
</x-app-layout>
