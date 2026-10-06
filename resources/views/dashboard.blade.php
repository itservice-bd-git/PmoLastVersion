<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">แดชบอร์ด</h2>
    </x-slot>

    <div class="max-w-7xl mx-auto space-y-6">

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <x-card>
                <p class="text-xs font-medium text-slate-500">โครงการที่กำลังดำเนินการ</p>
                <p class="text-2xl font-bold text-slate-900 mt-1">{{ $activeProjectsCount }}</p>
            </x-card>
            <x-card>
                <p class="text-xs font-medium text-slate-500">ใกล้ครบกำหนด (7 วัน)</p>
                <p class="text-2xl font-bold text-amber-600 mt-1">{{ $nearDueCount }}</p>
            </x-card>
            <x-card>
                <p class="text-xs font-medium text-slate-500">โครงการล่าช้า</p>
                <p class="text-2xl font-bold text-red-600 mt-1">{{ $delayedCount }}</p>
            </x-card>
            <x-card>
                <p class="text-xs font-medium text-slate-500">มีความเสี่ยง</p>
                <p class="text-2xl font-bold text-orange-600 mt-1">{{ $atRiskCount }}</p>
            </x-card>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <x-card>
                <p class="text-xs font-medium text-slate-500">ตู้ทั้งหมด</p>
                <p class="text-2xl font-bold text-slate-900 mt-1">{{ $totalCabinets }}</p>
            </x-card>
            <x-card>
                <p class="text-xs font-medium text-slate-500">ตู้ที่เสร็จสมบูรณ์</p>
                <p class="text-2xl font-bold text-emerald-600 mt-1">{{ $completedCabinets }}</p>
            </x-card>
            <x-card>
                <p class="text-xs font-medium text-slate-500">ตู้ที่อยู่ระหว่างผลิต</p>
                <p class="text-2xl font-bold text-blue-600 mt-1">{{ $inProductionCabinets }}</p>
            </x-card>
            <x-card>
                <p class="text-xs font-medium text-slate-500">ความคืบหน้าการผลิตเฉลี่ย</p>
                <p class="text-2xl font-bold text-slate-900 mt-1">{{ $avgProduction }}%</p>
            </x-card>
        </div>

        <x-card title="โครงการล่าสุด" subtitle="โครงการล่าสุดและความคืบหน้า">
            <x-slot name="actions">
                <a href="{{ route('projects.index') }}" class="text-sm text-blue-600 font-medium hover:underline">ดูทั้งหมด</a>
            </x-slot>

            <div class="overflow-x-auto -mx-5">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wide border-b border-slate-100">
                            <th class="px-5 py-2">โครงการ</th>
                            <th class="px-5 py-2">ลูกค้า</th>
                            <th class="px-5 py-2">กำหนดส่ง</th>
                            <th class="px-5 py-2">สถานะ</th>
                            <th class="px-5 py-2 w-48">การผลิต</th>
                            <th class="px-5 py-2">ความเสี่ยง</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($recentProjects as $project)
                            <tr class="hover:bg-blue-50">
                                <td class="px-5 py-3">
                                    <a href="{{ route('projects.show', $project) }}" class="font-medium text-blue-600 hover:underline">{{ $project->project_no }}</a>
                                    <p class="text-xs text-slate-500">{{ $project->project_name }}</p>
                                </td>
                                <td class="px-5 py-3 text-slate-600">{{ $project->customer_name }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ optional($project->due_date)->format('d/m/Y') ?? '-' }}</td>
                                <td class="px-5 py-3"><x-status-badge :status="$project->status" /></td>
                                <td class="px-5 py-3"><x-progress-bar :value="$project->production_progress" size="sm" /></td>
                                <td class="px-5 py-3">
                                    @php $risk = $project->risk_level; @endphp
                                    @if ($risk === 'overdue')
                                        <span class="text-xs font-semibold text-red-600">ล่าช้า</span>
                                    @elseif ($risk === 'at_risk')
                                        <span class="text-xs font-semibold text-orange-500">มีความเสี่ยง</span>
                                    @elseif ($risk === 'on_track')
                                        <span class="text-xs font-semibold text-emerald-600">ตามแผน</span>
                                    @else
                                        <span class="text-xs text-slate-400">-</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-6 text-center text-slate-400">ยังไม่มีโครงการ</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>
</x-app-layout>
