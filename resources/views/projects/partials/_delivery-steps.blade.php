{{-- A sent round's four steps (ProjectDelivery::step): ห่อ n/m → แจ้งเปิดบิล → ออกรถ → ส่งถึง, as the delivery department
     records them on "งานจัดส่ง". Needs $d. --}}
@php
    $st = $d->step();
    $total = max(1, count($d->cabinet_ids ?? []));
    $labels = ['ห่อ '.$d->packedCount().'/'.count($d->cabinet_ids ?? []), 'แจ้งเปิดบิล', 'ออกจากโรงงาน', 'ส่งถึง'];
@endphp
<ol class="grid max-w-md grid-cols-4 gap-1" aria-label="ขั้นการจัดส่ง: {{ $st['text'] }}">
    @foreach ($labels as $i => $label)
        @php
            $fill = $i === 0 ? ($st['n'] >= 1 ? 100 : (int) round($d->packedCount() / $total * 100)) : ($st['n'] > $i ? 100 : 0);
            $now = $st['n'] === $i;
        @endphp
        <li class="min-w-0">
            <span class="block h-1.5 overflow-hidden rounded-full bg-slate-200"><span class="block h-full {{ $now && $fill < 100 ? 'bg-sky-600' : 'bg-emerald-600' }}" style="width: {{ $fill }}%"></span></span>
            <span class="mt-0.5 block text-[11px] leading-tight {{ $now ? 'font-bold text-slate-900' : ($st['n'] > $i ? 'font-semibold text-emerald-700' : 'text-slate-500') }}">{{ $label }}</span>
        </li>
    @endforeach
</ol>
