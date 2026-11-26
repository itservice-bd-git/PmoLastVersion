{{-- Settings › ผู้ใช้งาน: everyone with an account, searched by name / username / department / team (UserController::index).
     A table from md up; below it each person is a row of its own, so แก้ไข / ลบ stay in reach on a phone. --}}
<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-slate-900">ตั้งค่า</h1>
    </x-slot>

    <x-settings-layout space="space-y-4">

        <div class="flex flex-wrap items-center gap-2">
            <form method="GET" role="search" class="relative min-w-0 flex-1 basis-64 sm:max-w-sm">
                <label for="user-search" class="sr-only">ค้นหาผู้ใช้งาน</label>
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
                <input id="user-search" type="search" name="q" value="{{ $q }}" placeholder="ค้นหาชื่อ / ชื่อผู้ใช้ / แผนก / ทีม"
                       class="h-11 w-full rounded-lg border-slate-300 pl-9 text-sm focus:border-blue-500 focus:ring-blue-500">
            </form>
            <p class="text-sm text-slate-600" role="status">
                @if ($q !== '')
                    พบ {{ number_format($users->total()) }} คน · <a href="{{ route('settings.users.index') }}" class="font-semibold text-blue-700 hover:underline">ล้างการค้นหา</a>
                @else
                    ทั้งหมด {{ number_format($users->total()) }} คน
                @endif
            </p>
            <a href="{{ route('settings.users.create') }}" class="ml-auto inline-flex h-11 items-center gap-2 rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
                เพิ่มผู้ใช้งาน
            </a>
        </div>

        <x-card class="overflow-hidden">
            <div class="-m-5">{{-- the card's own padding off: the rows run edge to edge --}}
            @if ($users->isEmpty())
                <div class="px-5 py-12 text-center">
                    @if ($q !== '')
                        <p class="text-base font-semibold text-slate-800">ไม่พบผู้ใช้งานที่ตรงกับ “{{ $q }}”</p>
                        <p class="mt-1 text-sm text-slate-600">ลองค้นหาด้วยชื่อ ชื่อผู้ใช้ แผนก หรือทีม · <a href="{{ route('settings.users.index') }}" class="font-semibold text-blue-700 hover:underline">ดูทั้งหมด</a></p>
                    @else
                        <p class="text-base font-semibold text-slate-800">ยังไม่มีผู้ใช้งาน</p>
                        <p class="mt-1 text-sm text-slate-600"><a href="{{ route('settings.users.create') }}" class="font-semibold text-blue-700 hover:underline">เพิ่มผู้ใช้งานคนแรก</a></p>
                    @endif
                </div>
            @else
                {{-- md and up: the table --}}
                <table class="hidden min-w-full text-sm md:table">
                    <thead>
                        <tr class="border-b border-slate-100 text-left text-xs font-semibold text-slate-500">
                            <th scope="col" class="px-5 py-3">ชื่อ</th>
                            <th scope="col" class="px-5 py-3">ชื่อผู้ใช้</th>
                            <th scope="col" class="px-5 py-3">บทบาท</th>
                            <th scope="col" class="px-5 py-3">แผนก · ทีม</th>
                            <th scope="col" class="px-5 py-3">สถานะ</th>
                            <th scope="col" class="px-5 py-3"><span class="sr-only">จัดการ</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($users as $user)
                            <tr class="hover:bg-slate-50">
                                <td class="max-w-[16rem] px-5 py-3 font-medium text-slate-800"><span class="block truncate" title="{{ $user->name }}">{{ $user->name }}</span></td>
                                <td class="max-w-[16rem] px-5 py-3 text-slate-600"><span class="block truncate" title="{{ $user->email }}">{{ $user->email }}</span></td>
                                <td class="px-5 py-3 text-slate-700">{{ $roles[$user->role] ?? $user->role }}</td>
                                <td class="px-5 py-3 text-slate-700">
                                    {{ $user->department?->name ?? '-' }}@if ($user->team)<span class="ml-1.5 inline-flex rounded-full bg-slate-100 px-2 text-xs font-medium text-slate-700">{{ $user->team->name }}</span>@endif
                                    @if ($user->extraDepartments->isNotEmpty())<span class="block text-xs text-slate-500">ดูแลเพิ่ม: {{ $user->extraDepartments->pluck('name')->implode(', ') }}</span>@endif
                                </td>
                                <td class="px-5 py-3">
                                    @if ($user->is_active)
                                        <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>ใช้งาน</span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500"><span class="h-1.5 w-1.5 rounded-full bg-slate-400" aria-hidden="true"></span>ปิดใช้งาน</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-2 text-right">
                                    <a href="{{ route('settings.users.edit', $user) }}" class="inline-flex h-9 items-center rounded-md px-2.5 text-sm font-medium text-blue-700 hover:bg-blue-50" aria-label="แก้ไข {{ $user->name }}">แก้ไข</a>
                                    <form method="POST" action="{{ route('settings.users.destroy', $user) }}" class="inline" data-confirm="ลบผู้ใช้งาน {{ $user->name }}? ลบแล้วกู้คืนไม่ได้" onsubmit="return confirm(this.dataset.confirm)">
                                        @csrf @method('DELETE')
                                        <button class="inline-flex h-9 items-center rounded-md px-2.5 text-sm font-medium text-red-700 hover:bg-red-50" aria-label="ลบ {{ $user->name }}">ลบ</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                {{-- phone: one row per person --}}
                <ul class="divide-y divide-slate-100 md:hidden">
                    @foreach ($users as $user)
                        <li class="flex items-start gap-3 px-4 py-3">
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold text-slate-800">{{ $user->name }}</p>
                                <p class="truncate text-sm text-slate-600">{{ $user->email }}</p>
                                <p class="mt-0.5 text-sm text-slate-700">
                                    {{ $roles[$user->role] ?? $user->role }} · {{ $user->department?->name ?? 'ไม่มีแผนก' }}@if ($user->team) · {{ $user->team->name }}@endif
                                    · <span class="{{ $user->is_active ? 'text-emerald-700' : 'text-slate-500' }} whitespace-nowrap font-semibold">{{ $user->is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}</span>
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end">
                                <a href="{{ route('settings.users.edit', $user) }}" class="inline-flex h-11 items-center rounded-lg px-3 text-sm font-semibold text-blue-700 hover:bg-blue-50" aria-label="แก้ไข {{ $user->name }}">แก้ไข</a>
                                <form method="POST" action="{{ route('settings.users.destroy', $user) }}" data-confirm="ลบผู้ใช้งาน {{ $user->name }}? ลบแล้วกู้คืนไม่ได้" onsubmit="return confirm(this.dataset.confirm)">
                                    @csrf @method('DELETE')
                                    <button class="inline-flex h-11 items-center rounded-lg px-3 text-sm font-semibold text-red-700 hover:bg-red-50" aria-label="ลบ {{ $user->name }}">ลบ</button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
            </div>
        </x-card>

        {{ $users->links() }}
    </x-settings-layout>
</x-app-layout>
