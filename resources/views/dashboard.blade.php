<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">Dashboard</h2>
    </x-slot>

    <div class="max-w-7xl mx-auto space-y-6">

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <x-card>
                <p class="text-xs font-medium text-slate-500">Active Projects</p>
                <p class="text-2xl font-bold text-slate-900 mt-1">{{ $activeProjectsCount }}</p>
            </x-card>
            <x-card>
                <p class="text-xs font-medium text-slate-500">Near Due Date (7 days)</p>
                <p class="text-2xl font-bold text-amber-600 mt-1">{{ $nearDueCount }}</p>
            </x-card>
            <x-card>
                <p class="text-xs font-medium text-slate-500">Delayed Projects</p>
                <p class="text-2xl font-bold text-red-600 mt-1">{{ $delayedCount }}</p>
            </x-card>
            <x-card>
                <p class="text-xs font-medium text-slate-500">At Risk</p>
                <p class="text-2xl font-bold text-orange-600 mt-1">{{ $atRiskCount }}</p>
            </x-card>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <x-card>
                <p class="text-xs font-medium text-slate-500">Total Cabinets</p>
                <p class="text-2xl font-bold text-slate-900 mt-1">{{ $totalCabinets }}</p>
            </x-card>
            <x-card>
                <p class="text-xs font-medium text-slate-500">Completed Cabinets</p>
                <p class="text-2xl font-bold text-emerald-600 mt-1">{{ $completedCabinets }}</p>
            </x-card>
            <x-card>
                <p class="text-xs font-medium text-slate-500">Cabinets In Production</p>
                <p class="text-2xl font-bold text-blue-600 mt-1">{{ $inProductionCabinets }}</p>
            </x-card>
            <x-card>
                <p class="text-xs font-medium text-slate-500">Avg. Production Progress</p>
                <p class="text-2xl font-bold text-slate-900 mt-1">{{ $avgProduction }}%</p>
            </x-card>
        </div>

        <x-card title="Recent Projects" subtitle="Latest projects and their progress">
            <x-slot name="actions">
                <a href="{{ route('projects.index') }}" class="text-sm text-blue-600 font-medium hover:underline">View all</a>
            </x-slot>

            <div class="overflow-x-auto -mx-5">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wide border-b border-slate-100">
                            <th class="px-5 py-2">Project</th>
                            <th class="px-5 py-2">Customer</th>
                            <th class="px-5 py-2">Due Date</th>
                            <th class="px-5 py-2">Status</th>
                            <th class="px-5 py-2 w-48">Production</th>
                            <th class="px-5 py-2">Risk</th>
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
                                        <span class="text-xs font-semibold text-red-600">Delayed</span>
                                    @elseif ($risk === 'at_risk')
                                        <span class="text-xs font-semibold text-orange-500">At Risk</span>
                                    @elseif ($risk === 'on_track')
                                        <span class="text-xs font-semibold text-emerald-600">On Track</span>
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
