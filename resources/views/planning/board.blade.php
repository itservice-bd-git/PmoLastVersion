<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">งานแผนกของฉัน</h2>
    </x-slot>

    {{--
        Planning, written the way the rest of the app is: this Blade page + partials + one Alpine component
        (partials/_script). Left "งานที่ต้องเสร็จ" panel, one toolbar, เดือน / ไทม์ไลน์ / แดชบอร์ด, and a wide project modal.
        Data = PlanningBoard (controller@board), every edit = one operation through planning.sync (PlanningSync), so the
        permissions and workflow rules are PMO's own. Bleeds to the page edges (negative margins cancel layouts/app's padding).
    --}}
    @if (! empty($noDepartment))
        <x-card>
            <p class="text-sm text-slate-600">บัญชีของคุณยังไม่ได้ผูกกับแผนก จึงยังไม่มีงานให้แสดง กรุณาติดต่อผู้ดูแลระบบเพื่อกำหนดแผนกในหน้า Users</p>
        </x-card>
    @else
    <div class="-m-4 sm:-m-6 lg:-m-8 flex flex-col lg:flex-row min-h-[calc(100vh-4rem)] bg-white"
         x-data="planningBoard(@js($config))" @keydown.escape.window="onEscape()">

        @include('planning.partials._left-panel')

        <div class="flex-1 min-w-0 flex flex-col">
            @include('planning.partials._toolbar')
            @include('planning.partials._month')
            @include('planning.partials._timeline')
            @include('planning.partials._dashboard')
        </div>

        @include('planning.partials._task-modal')
        @include('planning.partials._checklist-popover')
    </div>

    @push('scripts')
    @include('planning.partials._script')
    @endpush
    @endif
</x-app-layout>
