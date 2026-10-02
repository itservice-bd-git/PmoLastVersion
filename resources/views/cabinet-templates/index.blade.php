<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">Cabinet Templates</h2>
    </x-slot>

    <div class="max-w-5xl mx-auto space-y-4">
        <div class="flex justify-end">
            <a href="{{ route('cabinet-templates.create') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">
                + New Template
            </a>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach ($templates as $template)
                <a href="{{ route('cabinet-templates.show', $template) }}" class="block">
                    <x-card>
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="flex items-center gap-2">
                                    <h3 class="font-semibold text-slate-900">{{ $template->name }}</h3>
                                    @if ($template->is_default)
                                        <span class="text-[10px] uppercase tracking-wide bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded font-semibold">Default</span>
                                    @endif
                                    @if (!$template->is_active)
                                        <span class="text-[10px] uppercase tracking-wide bg-slate-100 text-slate-400 px-1.5 py-0.5 rounded font-semibold">Inactive</span>
                                    @endif
                                </div>
                                <p class="text-xs text-slate-500 mt-1">{{ $template->code }} &middot; ใช้งานโดย {{ $template->cabinets_count }} ตู้</p>
                            </div>
                        </div>
                        @if ($template->description)
                            <p class="text-sm text-slate-600 mt-2">{{ $template->description }}</p>
                        @endif
                        <div class="flex flex-wrap gap-2 mt-4">
                            @foreach ($template->taskTemplates as $taskTemplate)
                                <span class="text-xs bg-slate-100 text-slate-600 px-2 py-1 rounded-md">
                                    {{ $taskTemplate->name }} ({{ $taskTemplate->subtaskTemplates->count() }})
                                </span>
                            @endforeach
                        </div>
                    </x-card>
                </a>
            @endforeach
        </div>
    </div>
</x-app-layout>
