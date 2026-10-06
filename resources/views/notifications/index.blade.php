<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">การแจ้งเตือน</h2>
    </x-slot>

    <div class="max-w-3xl mx-auto">
        <x-card title="การแจ้งเตือนของฉัน">
            <x-slot name="actions">
                @if (auth()->user()->unreadNotifications()->exists())
                    <form method="POST" action="{{ route('notifications.read-all') }}">
                        @csrf
                        <button class="text-sm font-medium text-blue-600 hover:underline">อ่านทั้งหมดแล้ว</button>
                    </form>
                @endif
            </x-slot>

            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @forelse ($notifications as $n)
                    <form method="POST" action="{{ route('notifications.read', $n->id) }}">
                        @csrf
                        <button class="w-full text-left px-5 py-3 flex items-start gap-3 hover:bg-slate-50">
                            <span class="mt-1.5 w-2 h-2 rounded-full shrink-0 {{ $n->read_at ? 'bg-transparent' : 'bg-blue-500' }}"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm {{ $n->read_at ? 'text-slate-600' : 'font-semibold text-slate-900' }}">{{ $n->data['title'] }}</span>
                                <span class="block text-xs text-slate-500 truncate">{{ $n->data['body'] }}</span>
                                <span class="block text-[11px] text-slate-400 mt-0.5">{{ $n->created_at->format('d/m/Y H:i') }}</span>
                            </span>
                        </button>
                    </form>
                @empty
                    <p class="px-5 py-6 text-center text-slate-400 text-sm">ยังไม่มีการแจ้งเตือน</p>
                @endforelse
            </div>
            @if ($notifications->hasPages())
                <div class="mt-4 -mx-5 -mb-5 px-5 pb-2">{{ $notifications->links() }}</div>
            @endif
        </x-card>
    </div>
</x-app-layout>
