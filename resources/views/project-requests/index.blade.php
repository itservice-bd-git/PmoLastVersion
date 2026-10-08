<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">คำขอโครงการจากหน้าเว็บ</h2>
    </x-slot>

    <div class="max-w-4xl mx-auto space-y-6">
        <x-card title="รอพิจารณา ({{ $pending->count() }})" subtitle="ลิงก์ฟอร์มสำหรับส่งให้ผู้ขอ: {{ route('requests.create') }}">
            <div class="divide-y divide-slate-100 -mx-5 -mt-2">
                @forelse ($pending as $r)
                    <div class="px-5 py-4" x-data="{ mode: null }">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-slate-900">{{ $r->title }}</p>
                                <p class="text-sm text-slate-600">{{ $r->customer_name }}</p>
                                <p class="text-xs text-slate-500 mt-0.5">
                                    ผู้ขอ {{ $r->requester_name }} · {{ $r->contact }} · {{ $r->created_at->format('d/m/Y H:i') }}
                                    @if ($r->quantity) · {{ $r->quantity }} ตู้ @endif
                                    @if ($r->needed_by) · ต้องการภายใน {{ $r->needed_by->format('d/m/Y') }} @endif
                                </p>
                                @if ($r->description)
                                    <p class="mt-2 text-sm text-slate-700 whitespace-pre-line">{{ $r->description }}</p>
                                @endif
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <button type="button" @click="mode = mode === 'import' ? null : 'import'" class="px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700">นำเข้าเป็นโครงการ</button>
                                <button type="button" @click="mode = mode === 'reject' ? null : 'reject'" class="px-3 py-1.5 rounded-lg border border-red-300 text-red-700 text-xs font-semibold hover:bg-red-50">ปฏิเสธ</button>
                            </div>
                        </div>

                        <form x-show="mode === 'import'" x-cloak method="POST" action="{{ route('project-requests.import', $r) }}" class="mt-3 flex flex-wrap items-end gap-3 rounded-lg bg-slate-50 p-3">
                            @csrf
                            <div>
                                <x-input-label value="เลขที่โครงการ (Project No.)" />
                                <x-text-input name="project_no" class="mt-1 block w-48" :value="$suggestedNo" required maxlength="50" />
                            </div>
                            <p class="text-xs text-slate-500 pb-2">สร้างเป็นโครงการสถานะ Draft แล้วไปที่หน้าโครงการเพื่อกรอกข้อมูลต่อ</p>
                            <button class="px-3 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">ยืนยันนำเข้า</button>
                        </form>

                        <form x-show="mode === 'reject'" x-cloak method="POST" action="{{ route('project-requests.reject', $r) }}" class="mt-3 flex flex-wrap items-end gap-3 rounded-lg bg-red-50/50 p-3">
                            @csrf
                            <div class="flex-1 min-w-[16rem]">
                                <x-input-label value="เหตุผลที่ปฏิเสธ" />
                                <x-text-input name="reject_reason" class="mt-1 block w-full" required maxlength="500" />
                            </div>
                            <button class="px-3 py-2 rounded-lg bg-red-600 text-white text-sm font-semibold hover:bg-red-700">ยืนยันปฏิเสธ</button>
                        </form>
                    </div>
                @empty
                    <p class="px-5 py-6 text-sm text-slate-400 text-center">ไม่มีคำขอที่รอพิจารณา</p>
                @endforelse
            </div>
            <x-input-error :messages="$errors->get('project_no')" class="mt-3" />
            <x-input-error :messages="$errors->get('reject_reason')" class="mt-3" />
        </x-card>

        <x-card title="ดำเนินการแล้ว (ล่าสุด 30 รายการ)">
            <div class="divide-y divide-slate-100 -mx-5 -mt-2">
                @forelse ($handled as $r)
                    <div class="px-5 py-3 flex flex-wrap items-center justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-800 truncate">{{ $r->title }} <span class="text-slate-400 font-normal">· {{ $r->customer_name }}</span></p>
                            <p class="text-xs text-slate-500">
                                {{ $r->handler?->name ?? '-' }} · {{ $r->handled_at?->format('d/m/Y H:i') }}
                                @if ($r->reject_reason) · เหตุผล: {{ $r->reject_reason }} @endif
                            </p>
                        </div>
                        @if ($r->status === 'imported' && $r->project)
                            <a href="{{ route('projects.show', $r->project) }}" class="text-xs font-semibold text-emerald-700 hover:underline">นำเข้าแล้ว → {{ $r->project->project_no }}</a>
                        @else
                            <span class="text-xs font-semibold {{ $r->status === 'rejected' ? 'text-red-600' : 'text-slate-500' }}">{{ \App\Models\ProjectRequest::$statuses[$r->status] ?? $r->status }}</span>
                        @endif
                    </div>
                @empty
                    <p class="px-5 py-4 text-sm text-slate-400">ยังไม่มีประวัติ</p>
                @endforelse
            </div>
        </x-card>
    </div>
</x-app-layout>
