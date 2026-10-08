<x-guest-layout>
    <div class="text-center py-4">
        <p class="text-3xl">✓</p>
        <h1 class="mt-2 text-base font-semibold text-slate-900">ได้รับคำขอของคุณแล้ว</h1>
        <p class="mt-1 text-sm text-slate-500">ทีมงานจะพิจารณาและติดต่อกลับตามช่องทางที่คุณให้ไว้</p>
        <a href="{{ route('requests.create') }}" class="mt-4 inline-block text-sm font-medium text-blue-600 hover:underline">ยื่นคำขออื่น</a>
    </div>
</x-guest-layout>
