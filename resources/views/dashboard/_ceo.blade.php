{{-- The dashboard's "ภาพรวม CEO (ใหม่)" view; data from App\Services\CeoOverview ($ceo). The business side: contract value
     in hand and when it is due, on-time deliveries, what is late, next month's deliveries, customers, where work is stuck.
     Colour roles: navy = data, Avatar orange = brand / this month, red = late or at risk, green = done. --}}
@php
    $money = $ceo['money'];
    $baht = fn ($v) => $v === null ? '—' : '฿'.number_format($v);
    $mil = fn ($v) => $v === null ? '—' : '฿'.number_format($v / 1e6, 2).' ล.';
    $f = $ceo['focus'];
    $late = $ceo['late'];
    $h = $ceo['health'];

    // contract value due per month (bars): closed (navy) inside what is due (light navy); next month in red when it is behind
    $months = collect($ceo['months']);
    // a round axis (0, 3, 6, 9, 12 ล้าน): four steps of 1, 2, 2.5, 3 or 5 × a power of ten
    $raw = max(1, ($months->max('value') ?? 1) / 4);
    $mag = 10 ** floor(log10($raw));
    $step = collect([1, 2, 2.5, 3, 5, 10])->first(fn ($n) => $n * $mag >= $raw) * $mag;
    $max = $step * 4;
    $scale = 220 / $max;
    $X = fn ($i) => 90 + $i * 112;
    $base = 262;
    $ticks = collect(range(0, 4))->map(fn ($i) => $max / 4 * $i);
    $nextBehind = fn ($m) => $m['next'] && $m['progress'] !== null && $m['progress'] < $f['lowLimit'];

    $tone = ['red' => 'bg-[#C93C3C]', 'pink' => 'bg-[#F0A39F]', 'navy' => 'bg-[#2E4C8C]', 'green' => 'bg-[#12996B]'];
    $dot = ['red' => 'bg-[#C93C3C]', 'pink' => 'bg-[#F0A39F]', 'navy' => 'bg-[#2E4C8C]', 'green' => 'bg-[#12996B]'];
    $bands = collect($f['bands'])->filter(fn ($b) => $b['projects'] > 0)->values();
    $typeShade = ['bg-[#2E4C8C]', 'bg-[#7F96CB]', 'bg-[#C9D4EC]', 'bg-[#E4E9F4]'];
    $topCustomer = max(1, collect($ceo['customers'])->max(fn ($c) => $c['value'] ?? $c['projects']));
    $maxLate = max(1, collect($ceo['bottlenecks']['rows'])->max('late'));
    $statusTotal = max(1, $h['total']);
    $on = $ceo['onTime'];
    $C = 2 * M_PI * 62;
@endphp

<div class="space-y-4 text-[#141A26]">
    {{-- title row: what this is, as of when, the CEO report --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h2 class="text-2xl font-semibold">ภาพรวมผู้บริหาร</h2>
            <p class="mt-1 flex items-center gap-2 text-sm text-[#5F6B7D]"><span class="h-2 w-2 rounded-full bg-[#12996B] ring-[3px] ring-[#12996B]/15"></span>ข้อมูล ณ {{ $ceo['asOf']->thaiDateTime() }} น.</p>
        </div>
        <a href="{{ route('reports.ceo') }}" target="_blank" rel="noopener"
           class="inline-flex min-h-10 items-center gap-2 rounded-xl bg-[#B35505] px-4 text-sm font-semibold text-white hover:bg-[#9A4804] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E8770F]">
            <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0l-4.5-4.5M12 15l4.5-4.5M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
            ส่งออกรายงาน CEO
        </a>
    </div>

    <div class="grid grid-cols-12 gap-4">
        {{-- the work in hand and when its money is due --}}
        <section aria-labelledby="ceo-bk" class="col-span-12 rounded-2xl border border-[#E6E8EE] bg-white p-5 shadow-[0_1px_2px_rgba(16,24,40,.05)] xl:col-span-8">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h3 id="ceo-bk" class="text-lg font-semibold">มูลค่างานในมือ</h3>
                    <p class="text-[13px] text-[#5F6B7D]">โครงการที่กำลังทำ {{ $ceo['backlog']['projects'] }} โครงการ @if ($money)(ระบุมูลค่า {{ $ceo['backlog']['valued'] }})@endif</p>
                    @if ($money)
                        <p class="mt-2 flex items-baseline gap-2"><span class="text-[56px] font-medium leading-none tracking-tight tabular-nums">฿{{ number_format($ceo['backlog']['value'] / 1e6, 2) }}</span><span class="text-[22px] font-medium text-[#A85205]">ล้าน</span></p>
                    @else
                        <p class="mt-2 text-[56px] font-medium leading-none tabular-nums">{{ $ceo['backlog']['projects'] }} <span class="text-[22px] text-[#5F6B7D]">โครงการ</span></p>
                    @endif
                </div>
                @if ($money)
                    <p class="flex gap-4 text-xs text-[#5F6B7D]">
                        <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-[3px] bg-[#2E4C8C]"></span>ปิดโครงการแล้ว</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-[3px] bg-[#DFE6F3] ring-1 ring-inset ring-[#9DB0DA]"></span>ยังต้องส่งมอบ</span>
                    </p>
                @endif
            </div>
            @if ($money)
                <p class="mb-1 mt-4 text-sm">มูลค่างานที่ครบกำหนดส่งมอบรายเดือน</p>
                <svg viewBox="0 0 740 326" class="block h-auto w-full" role="img"
                     aria-label="มูลค่างานที่ครบกำหนดส่งมอบรายเดือน: {{ $months->map(fn ($m) => $m['label'].' '.number_format(($m['value'] ?? 0) / 1e6, 2).' ล้าน')->implode(', ') }}">
                    <g stroke="#EEF0F4">@foreach ($ticks as $t)<line x1="40" y1="{{ $base - $t * $scale }}" x2="730" y2="{{ $base - $t * $scale }}"></line>@endforeach</g>
                    <g font-size="13" fill="#626B7D" text-anchor="end">@foreach ($ticks as $t)<text x="34" y="{{ $base - $t * $scale + 4 }}">{{ rtrim(rtrim(number_format($t / 1e6, 2), '0'), '.') }}</text>@endforeach</g>
                    <text x="40" y="14" font-size="13" fill="#626B7D">ล้านบาท</text>
                    @foreach ($months as $i => $m)
                        @php $x = $X($i); $hv = $m['value'] * $scale; $hc = $m['closed'] * $scale; $warn = $nextBehind($m); @endphp
                        @if ($m['value'] > 0)
                            <rect x="{{ $x - 32 }}" y="{{ $base - $hv }}" width="64" height="{{ $hv }}" rx="8" fill="{{ $warn ? '#FBE1E0' : '#DFE6F3' }}" stroke="{{ $warn ? '#C93C3C' : '#9DB0DA' }}"></rect>
                        @endif
                        @if ($m['closed'] > 0)
                            <rect x="{{ $x - 32 }}" y="{{ $base - max($hc, 4) }}" width="64" height="{{ max($hc, 4) }}" rx="8" fill="#2E4C8C"></rect>
                        @endif
                        <text x="{{ $x }}" y="{{ $base - max($hv, 4) - 12 }}" text-anchor="middle" font-size="{{ $warn ? 20 : 16 }}" font-weight="{{ $warn ? 600 : 500 }}" fill="{{ $m['value'] ? '#141A26' : '#626B7D' }}">{{ $m['value'] ? '฿'.number_format($m['value'] / 1e6, 2).' ล.' : '–' }}</text>
                        <text x="{{ $x }}" y="{{ $base + 24 }}" text-anchor="middle" font-size="15" fill="{{ $m['current'] ? '#A85205' : '#5F6B7D' }}" @if ($m['current']) font-weight="600" @endif>{{ $m['label'] }}</text>
                        @if ($m['current'])<rect x="{{ $x - 14 }}" y="{{ $base + 30 }}" width="28" height="3" rx="1.5" fill="#E8770F"></rect>@endif
                        <text x="{{ $x }}" y="{{ $base + 50 }}" text-anchor="middle" font-size="13" fill="#626B7D">{{ $m['projects'] ? $m['projects'].' โครงการ' : '' }}</text>
                        @if ($warn)
                            <rect x="{{ $x + 44 }}" y="{{ $base - $hv + 10 }}" width="176" height="52" rx="10" fill="#FFFFFF" stroke="#C93C3C" stroke-opacity=".6"></rect>
                            <text x="{{ $x + 58 }}" y="{{ $base - $hv + 31 }}" font-size="14" font-weight="600" fill="#B42F2F">ผลิตเฉลี่ยเพิ่ง {{ $m['progress'] }}%</text>
                            <text x="{{ $x + 58 }}" y="{{ $base - $hv + 50 }}" font-size="13" fill="#5F6B7D">ยังไม่เริ่มส่งมอบ</text>
                        @endif
                    @endforeach
                </svg>
            @endif
        </section>

        {{-- key numbers --}}
        <div class="col-span-12 grid grid-cols-2 gap-4 sm:grid-cols-3 xl:col-span-4 xl:grid-cols-2">
            <div class="col-span-2 flex items-center gap-5 rounded-2xl border border-[#E6E8EE] bg-white p-5 shadow-[0_1px_2px_rgba(16,24,40,.05)] sm:col-span-3 xl:col-span-2">
                <svg viewBox="0 0 150 150" class="h-[130px] w-[130px] shrink-0" role="img" aria-label="ส่งมอบตรงเวลา {{ $on['onTime'] }} จาก {{ $on['rounds'] }} รอบ">
                    <circle cx="75" cy="75" r="62" fill="none" stroke="#EEF0F4" stroke-width="12"></circle>
                    @if ($on['rounds'])<circle cx="75" cy="75" r="62" fill="none" stroke="#12996B" stroke-width="12" stroke-linecap="round" stroke-dasharray="{{ max(0, $C * $on['onTime'] / $on['rounds'] - 6) }} {{ $C }}" transform="rotate(-90 75 75)"></circle>@endif
                    <text x="75" y="80" text-anchor="middle" font-size="32" font-weight="500" fill="#141A26">{{ $on['rate'] !== null ? $on['rate'].'%' : '–' }}</text>
                    <text x="75" y="102" text-anchor="middle" font-size="12" fill="#5F6B7D">{{ $on['onTime'] }} / {{ $on['rounds'] }} รอบ</text>
                </svg>
                <div>
                    <p class="text-sm text-[#5F6B7D]">ส่งมอบตรงเวลา</p>
                    <p class="mt-1 text-[15px] font-medium">{{ $on['onTime'] }} จาก {{ $on['rounds'] }} รอบส่ง เดือน {{ $on['month'] }}</p>
                    <p class="mt-1 text-[13px] text-[#626B7D]">{{ $on['rounds'] ? 'ส่งช้ากว่านัด '.($on['rounds'] - $on['onTime']).' รอบ' : 'เดือนนี้ยังไม่มีรอบที่ส่งถึง' }}</p>
                </div>
            </div>
            @foreach ([
                ['โครงการเลยกำหนด', $late['projects'], 'text-[#C93C3C]', $money ? 'มูลค่า '.$baht($late['value']) : 'กำลังทำ แต่เลยวันส่ง'],
                ['ปิดโครงการ '.$on['month'], $ceo['closed']['projects'], 'text-[#0E7A55]', $money ? 'มูลค่า '.$mil($ceo['closed']['value']) : 'ปิดในเดือนนี้'],
                ['ส่งมอบตู้ '.$ceo['cabinets']['month'], $ceo['cabinets']['done'].' / '.$ceo['cabinets']['planned'], '', 'เลยกำหนดแล้ว '.$ceo['cabinets']['overdue'].' ตู้'],
                ['รอเซลแจ้งวันส่ง', $ceo['pending'], '', 'ถูกเลื่อน ยังไม่มีวันใหม่'],
            ] as [$label, $n, $color, $sub])
                <div class="flex flex-col justify-center gap-1 rounded-2xl border border-[#E6E8EE] bg-white px-5 py-4 shadow-[0_1px_2px_rgba(16,24,40,.05)]">
                    <span class="text-sm text-[#5F6B7D]">{{ $label }}</span>
                    <span class="text-[32px] font-medium leading-tight tabular-nums {{ $color }}">{{ $n }}</span>
                    <span class="text-[13px] text-[#626B7D]">{{ $sub }}</span>
                </div>
            @endforeach
        </div>

        {{-- next month: what must go out and how far it is made --}}
        <section aria-labelledby="ceo-next" class="col-span-12 rounded-2xl border border-[#F1D3D1] bg-[#FFFBFA] p-5 lg:col-span-7">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 id="ceo-next" class="text-lg font-semibold">ต้องเร่ง: งานส่งมอบเดือน {{ $f['month'] }}</h3>
                    <p class="mt-1 text-sm">{{ $f['projects'] }} โครงการ @if ($money) มูลค่า <span class="font-medium tabular-nums">{{ '฿'.number_format($f['value'] / 1e6, 2) }} ล้าน</span> @endif · ผลิตเฉลี่ย <span class="font-medium text-[#B42F2F]">{{ $f['progress'] }}%</span></p>
                </div>
                <div class="text-right">
                    <p class="text-sm text-[#5F6B7D]">ผลิตยังไม่ถึง {{ $f['lowLimit'] }}%</p>
                    <p><span class="text-[28px] font-medium tabular-nums text-[#C93C3C]">{{ $f['low'] }}</span> <span class="text-[13px] text-[#626B7D]">โครงการ @if ($money)· {{ '฿'.number_format($f['lowValue'] / 1e6, 2) }} ล้าน @endif</span></p>
                </div>
            </div>
            @if ($f['top'])
                <div class="mt-3 hidden grid-cols-[minmax(0,1fr)_112px_104px] gap-3.5 text-[13px] text-[#5F6B7D] sm:grid"><span>{{ $money ? '5 โครงการมูลค่าสูงสุด' : 'โครงการที่ต้องส่ง' }}</span><span>กำหนดส่ง · ผลิต</span><span class="text-right">{{ $money ? 'มูลค่า' : '' }}</span></div>
                <ul>
                    @foreach ($f['top'] as $p)
                        @php $lowP = $p['progress'] < $f['lowLimit']; @endphp
                        <li class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3.5 gap-y-2 border-t border-[#F3E3E2] py-2.5 sm:grid-cols-[minmax(0,1fr)_112px_104px]">
                            <a href="{{ $p['url'] }}" class="min-w-0 hover:underline"><span class="block text-[15px] font-semibold">{{ $p['no'] }}</span><span class="block truncate text-[13px] text-[#5F6B7D]">{{ $p['name'] }} · {{ $p['customer'] }}</span></a>
                            <span class="col-span-2 sm:col-span-1 sm:row-auto">
                                <span class="flex justify-between text-xs text-[#5F6B7D]"><span>ส่ง {{ $p['due'] }}</span><span class="font-medium tabular-nums {{ $lowP ? 'text-[#B42F2F]' : 'text-[#141A26]' }}">{{ $p['progress'] }}%</span></span>
                                <span class="mt-1.5 block h-1.5 rounded-full bg-[#EEF0F4]"><span class="block h-full min-w-[3px] rounded-full {{ $lowP ? 'bg-[#C93C3C]' : 'bg-[#2E4C8C]' }}" style="width: {{ $p['progress'] }}%"></span></span>
                            </span>
                            <span class="col-start-2 row-start-1 text-right text-[15px] tabular-nums sm:col-start-auto sm:row-start-auto">{{ $money ? $baht($p['value']) : '' }}</span>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-3 border-t border-[#F1D3D1] pt-4">
                    <p class="flex flex-wrap justify-between gap-2 text-sm"><span>งาน {{ $f['month'] }} แบ่งตามความคืบหน้าการผลิต{{ $money ? ' (มูลค่า)' : '' }}</span></p>
                    <div class="my-3 flex h-3.5 gap-1" role="img" aria-label="{{ $bands->map(fn ($b) => $b['label'].' '.$b['projects'].' โครงการ')->implode(', ') }}">
                        @foreach ($bands as $b)<span class="rounded-md {{ $tone[$b['tone']] }}" style="flex: {{ $money ? max(1, round(($b['value'] ?: 1) / 1e4)) : $b['projects'] }}"></span>@endforeach
                    </div>
                    <ul class="grid gap-3" style="grid-template-columns: repeat({{ max(1, $bands->count()) }}, minmax(0, 1fr))">
                        @foreach ($bands as $b)
                            <li><span class="flex items-center gap-1.5 text-sm text-[#5F6B7D]"><span class="h-2 w-2 rounded-full {{ $dot[$b['tone']] }}"></span>{{ $b['label'] }}</span>
                                <span class="text-xl font-medium tabular-nums">{{ $money ? '฿'.number_format($b['value'] / 1e6, 2).' ล.' : $b['projects'] }}</span> <span class="text-[13px] text-[#626B7D]">{{ $b['projects'] }} โครงการ</span></li>
                        @endforeach
                    </ul>
                    @if ($f['firstWeek']['projects'])
                        <p class="mt-3.5 text-[13px]">สัปดาห์แรกของ {{ $f['month'] }} (1–7) ต้องส่ง {{ $f['firstWeek']['projects'] }} โครงการ @if ($money)<span class="font-medium">{{ '฿'.number_format($f['firstWeek']['value'] / 1e6, 2) }} ล้าน</span>@endif ผลิตเฉลี่ย <span class="font-medium text-[#B42F2F]">{{ $f['firstWeek']['progress'] }}%</span></p>
                    @endif
                </div>
            @else
                <p class="mt-4 rounded-xl bg-white px-4 py-6 text-center text-sm text-[#5F6B7D]">ไม่มีโครงการที่ต้องส่งเดือน {{ $f['month'] }}</p>
            @endif
        </section>

        {{-- what the work in hand is made of, and for whom --}}
        <section aria-labelledby="ceo-mix" class="col-span-12 rounded-2xl border border-[#E6E8EE] bg-white p-5 shadow-[0_1px_2px_rgba(16,24,40,.05)] lg:col-span-5">
            <h3 id="ceo-mix" class="text-lg font-semibold">{{ $money ? 'มูลค่างานในมือ' : 'งานในมือ' }}ตามประเภทงาน</h3>
            @php $typeTotal = max(1, collect($ceo['types'])->sum(fn ($t) => $t['value'] ?? $t['projects'])); @endphp
            <div class="mb-1.5 mt-4 flex h-3.5 gap-1" role="img" aria-label="{{ collect($ceo['types'])->map(fn ($t) => $t['name'].' '.$t['projects'].' โครงการ')->implode(', ') }}">
                @foreach ($ceo['types'] as $i => $t)<span class="rounded-md {{ $typeShade[min($i, 3)] }}" style="flex: {{ max(1, round(($t['value'] ?? $t['projects']) / $typeTotal * 100)) }}"></span>@endforeach
            </div>
            <ul>
                @foreach ($ceo['types'] as $i => $t)
                    <li class="flex items-baseline justify-between border-t border-[#EEF0F4] py-2">
                        <span class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-[3px] {{ $typeShade[min($i, 3)] }}"></span>{{ $t['name'] }} <span class="text-[13px] text-[#626B7D]">{{ $t['projects'] }} โครงการ</span></span>
                        @if ($money)<span><span class="font-medium tabular-nums">{{ $mil($t['value']) }}</span> <span class="text-[13px] text-[#626B7D]">{{ round(($t['value'] ?? 0) / $typeTotal * 100) }}%</span></span>@endif
                    </li>
                @endforeach
            </ul>
            <h3 class="mt-5 text-lg font-semibold">ลูกค้ารายใหญ่ (งานที่กำลังทำ)</h3>
            <ul class="mt-1.5">
                @foreach ($ceo['customers'] as $c)
                    <li class="border-t border-[#EEF0F4] py-2">
                        <span class="flex items-baseline justify-between gap-2"><span class="min-w-0 truncate">{{ $c['name'] }} <span class="text-[13px] text-[#626B7D]">{{ $c['projects'] }} โครงการ</span></span>@if ($money)<span class="shrink-0 tabular-nums">{{ $baht($c['value']) }}</span>@endif</span>
                        <span class="mt-1.5 block h-1.5 rounded-full bg-[#EEF0F4]"><span class="block h-full rounded-full bg-[#2E4C8C]" style="width: {{ round(($c['value'] ?? $c['projects']) / $topCustomer * 100) }}%"></span></span>
                    </li>
                @endforeach
            </ul>
        </section>

        {{-- how each department works: finished in the last 30 days and how much on time, what it holds now and how late --}}
        @php
            $dp = $ceo['departments'];
            $chip = ['jam' => ['คอขวด', 'bg-[#FDF0EF] text-[#B42F2F] ring-[#F1C4C1]'], 'watch' => ['ต้องติดตาม', 'bg-[#EEF0F4] text-[#3A4354] ring-[#D9DEE6]'], 'ok' => ['ปกติ', 'bg-[#E7F6EF] text-[#0E7A55] ring-[#BFE5D3]']];
            $rateBar = fn ($r) => $r === null ? 'bg-[#B5BCC8]' : ($r >= 80 ? 'bg-[#12996B]' : ($r >= $dp['watch'] ? 'bg-[#2E4C8C]' : 'bg-[#C93C3C]'));
            $trend = function ($now, $before) {
                if ($before === 0) { return $now ? ['ใหม่', 'text-[#0E7A55]'] : ['–', 'text-[#626B7D]']; }
                $pct = (int) round(($now - $before) / $before * 100);
                return [($pct >= 0 ? '▲ ' : '▼ ').abs($pct).'%', $pct >= 0 ? 'text-[#0E7A55]' : 'text-[#B42F2F]'];
            };
            [$tText, $tTone] = $trend($dp['done'], $dp['prev']);
        @endphp
        <section aria-labelledby="ceo-dept" class="col-span-12 rounded-2xl border border-[#E6E8EE] bg-white p-5 shadow-[0_1px_2px_rgba(16,24,40,.05)]">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 id="ceo-dept" class="text-lg font-semibold">ผลงานแต่ละแผนก</h3>
                <span class="text-[13px] text-[#5F6B7D]">งานย่อย (Sub Task) 30 วันล่าสุด เทียบ 30 วันก่อนหน้า · งานค้างนับเฉพาะโครงการที่ยังไม่ปิด</span>
            </div>
            <dl class="mt-4 grid grid-cols-2 gap-4 rounded-xl bg-[#F6F7F9] px-4 py-3.5 md:grid-cols-4">
                <div><dt class="text-sm text-[#5F6B7D]">งานที่เสร็จ 30 วัน</dt><dd class="flex items-baseline gap-2"><span class="text-[28px] font-medium tabular-nums">{{ number_format($dp['done']) }}</span><span class="text-[13px] font-medium {{ $tTone }}">{{ $tText }}</span></dd><dd class="text-[13px] text-[#626B7D]">30 วันก่อนหน้า {{ number_format($dp['prev']) }}</dd></div>
                <div><dt class="text-sm text-[#5F6B7D]">เสร็จตรงเวลา</dt><dd class="text-[28px] font-medium tabular-nums {{ $dp['rate'] !== null && $dp['rate'] < $dp['watch'] ? 'text-[#C93C3C]' : '' }}">{{ $dp['rate'] !== null ? $dp['rate'].'%' : '–' }}</dd><dd class="text-[13px] text-[#626B7D]">ของงานที่มีกำหนดเสร็จ</dd></div>
                <div><dt class="text-sm text-[#5F6B7D]">งานค้างอยู่</dt><dd class="text-[28px] font-medium tabular-nums">{{ number_format($dp['open']) }}</dd><dd class="text-[13px] text-[#626B7D]">ยังไม่เสร็จ</dd></div>
                <div><dt class="text-sm text-[#5F6B7D]">เลยกำหนด</dt><dd class="text-[28px] font-medium tabular-nums text-[#C93C3C]">{{ number_format($dp['late']) }}</dd><dd class="text-[13px] text-[#626B7D]">ใน {{ $ceo['bottlenecks']['cabinets'] }} ตู้</dd></div>
            </dl>
            @if ($dp['rows'])
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[900px] text-sm">
                        <thead><tr class="border-b border-[#E6E8EE] text-left text-[13px] text-[#5F6B7D]">
                            <th class="px-3 pb-2.5 pt-2 font-semibold">แผนก</th>
                            <th class="w-[150px] px-3 pb-2.5 pt-2 text-right font-semibold">งานเสร็จ 30 วัน</th>
                            <th class="w-[200px] px-3 pb-2.5 pt-2 font-semibold">เสร็จตรงเวลา</th>
                            <th class="w-[100px] px-3 pb-2.5 pt-2 text-right font-semibold">งานค้าง</th>
                            <th class="w-[140px] px-3 pb-2.5 pt-2 text-right font-semibold">เลยกำหนด</th>
                            <th class="w-[110px] px-3 pb-2.5 pt-2 text-right font-semibold">ค้างนานสุด</th>
                            <th class="w-[120px] px-3 pb-2.5 pt-2 font-semibold">สถานะ</th></tr></thead>
                        <tbody>
                            @foreach ($dp['rows'] as $d)
                                @php [$dt, $dtTone] = $trend($d['done'], $d['prev']); [$cText, $cTone] = $chip[$d['state']]; @endphp
                                <tr class="border-b border-[#F0F2F5] last:border-0">
                                    <td class="px-3 py-3"><a href="{{ $d['url'] }}" class="font-medium hover:underline">{{ $d['name'] }}</a></td>
                                    <td class="px-3 py-3 text-right"><span class="tabular-nums">{{ $d['done'] }}</span> <span class="text-xs font-medium {{ $dtTone }}">{{ $dt }}</span><span class="block text-xs text-[#626B7D]">ก่อนหน้า {{ $d['prev'] }}</span></td>
                                    <td class="px-3 py-3">
                                        <span class="flex justify-between text-[13px]"><span class="font-medium tabular-nums {{ $d['rate'] !== null && $d['rate'] < $dp['watch'] ? 'text-[#B42F2F]' : '' }}">{{ $d['rate'] !== null ? $d['rate'].'%' : '–' }}</span><span class="text-[#626B7D]">{{ $d['onTime'] }}/{{ $d['dated'] }}</span></span>
                                        <span class="mt-1.5 block h-1.5 rounded-full bg-[#EEF0F4]"><span class="block h-full rounded-full {{ $rateBar($d['rate']) }}" style="width: {{ $d['rate'] ?? 0 }}%"></span></span>
                                    </td>
                                    <td class="px-3 py-3 text-right tabular-nums">{{ $d['open'] }}</td>
                                    <td class="px-3 py-3 text-right">@if ($d['late'])<span class="font-medium tabular-nums text-[#C93C3C]">{{ $d['late'] }}</span> <span class="text-xs text-[#626B7D]">{{ $d['lateCabinets'] }} ตู้</span>@else<span class="text-[#626B7D]">0</span>@endif</td>
                                    <td class="px-3 py-3 text-right tabular-nums {{ $d['oldest'] >= 30 ? 'text-[#B42F2F]' : '' }}">{{ $d['oldest'] ? $d['oldest'].' วัน' : '–' }}</td>
                                    <td class="px-3 py-3"><span class="inline-block min-w-[84px] rounded-full px-3 py-1 text-center text-xs font-semibold ring-1 ring-inset {{ $cTone }}">{{ $cText }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-[13px] text-[#626B7D]">คอขวด = เลยกำหนดตั้งแต่ 20 งาน · ต้องติดตาม = มีงานเลยกำหนด หรือเสร็จตรงเวลาต่ำกว่า {{ $dp['watch'] }}% · กดชื่อแผนกเพื่อดูงานของแผนกนั้น</p>
            @else
                <p class="mt-4 rounded-xl bg-[#F6F7F9] px-4 py-6 text-center text-sm text-[#5F6B7D]">ยังไม่มีงานของแผนกในช่วงนี้</p>
            @endif
        </section>

        {{-- every visible project in one of four states --}}
        <section aria-labelledby="ceo-health" class="col-span-12 rounded-2xl border border-[#E6E8EE] bg-white p-5 shadow-[0_1px_2px_rgba(16,24,40,.05)] md:col-span-6">
            <h3 id="ceo-health" class="text-lg font-semibold">สุขภาพโครงการ</h3>
            <p class="text-[13px] text-[#5F6B7D]">ทั้งหมด {{ $h['total'] }} โครงการ</p>
            @php $states = [['ตามแผน', $h['inProgress'], 'bg-[#2E4C8C]', ''], ['ล่าช้า', $h['delayed'], 'bg-[#C93C3C]', 'text-[#C93C3C]'], ['รอเริ่ม', $h['notStarted'] + $h['onHold'], 'bg-[#B5BCC8]', ''], ['เสร็จสิ้น', $h['completed'], 'bg-[#12996B]', 'text-[#0E7A55]']]; @endphp
            <div class="my-4 flex h-3 gap-1" role="img" aria-label="{{ collect($states)->map(fn ($s) => $s[0].' '.$s[1])->implode(', ') }}">
                @foreach ($states as [$l, $n, $bg])@if ($n)<span class="rounded-md {{ $bg }}" style="flex: {{ $n }}"></span>@endif @endforeach
            </div>
            <ul class="grid grid-cols-2 gap-x-3 gap-y-4">
                @foreach ($states as [$l, $n, $bg, $tc])
                    <li><span class="flex items-center gap-2 text-sm text-[#5F6B7D]"><span class="h-2 w-2 rounded-full {{ $bg }}"></span>{{ $l }}</span>
                        <span class="text-[30px] font-normal tabular-nums {{ $tc }}">{{ $n }}</span> <span class="text-[13px] text-[#626B7D]">{{ round($n / $statusTotal * 100) }}%</span></li>
                @endforeach
            </ul>
            <p class="mt-4 flex items-baseline justify-between border-t border-[#EEF0F4] pt-3.5"><span class="text-sm text-[#5F6B7D]">ผลิตเฉลี่ยงานที่กำลังทำ</span><span class="text-2xl tabular-nums">{{ $h['progress'] }}%</span></p>
        </section>

        {{-- late only on paper: gone out of the factory, not closed in the system --}}
        @php $cl = $ceo['closable']; @endphp
        <section aria-labelledby="ceo-cl" class="col-span-12 rounded-2xl border border-[#E6E8EE] bg-white p-5 shadow-[0_1px_2px_rgba(16,24,40,.05)] md:col-span-6">
            <h3 id="ceo-cl" class="text-lg font-semibold">ปิดงานได้ทันที</h3>
            <p class="text-[13px] text-[#5F6B7D]">เลยกำหนดแต่ส่งของออกจากโรงงานแล้ว ยังไม่ปิดโครงการในระบบ</p>
            @if ($cl['projects'])
                <p class="mb-2.5 mt-4 flex items-baseline gap-2.5"><span class="text-[32px] font-medium tabular-nums text-[#0E7A55]">{{ $cl['projects'] }}</span><span class="text-[13px] text-[#626B7D]">โครงการ{{ $money ? ' · มูลค่า' : '' }}</span>@if ($money)<span class="text-2xl tabular-nums">{{ $baht($cl['value']) }}</span>@endif</p>
                <ul>
                    @foreach ($cl['rows'] as $r)
                        <li class="flex justify-between gap-3 border-t border-[#EEF0F4] py-2"><a href="{{ $r['url'] }}" class="min-w-0 hover:underline"><span class="block font-semibold">{{ $r['no'] }}</span><span class="block truncate text-[13px] text-[#5F6B7D]">{{ $r['name'] }} · {{ $r['customer'] }}</span></a>@if ($money)<span class="shrink-0 tabular-nums">{{ $baht($r['value']) }}</span>@endif</li>
                    @endforeach
                </ul>
                <p class="mt-3 text-[13px]">ปิด {{ $cl['projects'] }} โครงการนี้ โครงการล่าช้าจะเหลือ {{ $cl['leftProjects'] }}{{ $money ? ' ('.$baht($cl['leftValue']).')' : '' }}</p>
            @else
                <p class="mt-4 rounded-xl bg-[#F6F7F9] px-4 py-6 text-center text-sm text-[#5F6B7D]">ไม่มีโครงการที่ส่งแล้วแต่ค้างปิด</p>
            @endif
        </section>

        {{-- the late projects, worth most first --}}
        <section aria-labelledby="ceo-late" class="col-span-12 rounded-2xl border border-[#E6E8EE] bg-white p-5 shadow-[0_1px_2px_rgba(16,24,40,.05)]">
            <div class="mb-3.5 flex flex-wrap items-baseline justify-between gap-2"><h3 id="ceo-late" class="text-lg font-semibold">โครงการที่เลยกำหนดส่ง</h3>
                <span class="text-[13px] text-[#5F6B7D]">{{ $late['projects'] }} โครงการ{{ $money ? ' · มูลค่ารวม '.$baht($late['value']) : '' }}</span></div>
            @if ($late['rows'])
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[860px] text-sm">
                        <thead><tr class="border-b border-[#E6E8EE] text-left text-[13px] text-[#5F6B7D]">
                            <th class="w-[120px] px-3 pb-2.5 font-semibold">SO</th><th class="px-3 pb-2.5 font-semibold">โครงการ / ลูกค้า</th>
                            @if ($money)<th class="w-[120px] px-3 pb-2.5 text-right font-semibold">มูลค่า</th>@endif
                            <th class="w-[160px] px-3 pb-2.5 font-semibold">ผลิต</th><th class="px-3 pb-2.5 font-semibold">สถานะงาน</th><th class="w-[110px] px-3 pb-2.5 font-semibold">เลยกำหนด</th></tr></thead>
                        <tbody>
                            @foreach ($late['rows'] as $r)
                                <tr class="border-b border-[#F0F2F5] last:border-0">
                                    <td class="px-3 py-3"><a href="{{ $r['url'] }}" class="font-semibold hover:underline">{{ $r['no'] }}</a></td>
                                    <td class="px-3 py-3"><span class="block">{{ $r['name'] }}</span><span class="block text-[13px] text-[#5F6B7D]">{{ $r['customer'] }}</span></td>
                                    @if ($money)<td class="px-3 py-3 text-right tabular-nums">{{ $baht($r['value']) }}</td>@endif
                                    <td class="px-3 py-3"><span class="tabular-nums">{{ $r['progress'] }}%</span>
                                        <span class="mt-1.5 block h-1.5 rounded-full bg-[#EEF0F4]"><span class="block h-full rounded-full {{ $r['progress'] >= 100 ? 'bg-[#12996B]' : 'bg-[#2E4C8C]' }}" style="width: {{ min(100, $r['progress']) }}%"></span></span></td>
                                    <td class="px-3 py-3 {{ $r['shipped'] ? 'text-[#0E7A55]' : 'text-[#3A4354]' }}">{{ $r['shipped'] ? 'ส่งออกแล้ว · รอปิดโครงการ' : ($r['progress'] >= 100 ? 'ผลิตครบ · รอส่งมอบ' : 'ยังผลิตไม่ครบ') }}</td>
                                    <td class="px-3 py-3"><span class="inline-block min-w-[84px] rounded-full bg-[#FDF0EF] px-3 py-1 text-center text-xs font-semibold text-[#B42F2F] ring-1 ring-inset ring-[#F1C4C1]">เลย {{ $r['days'] }} วัน</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="rounded-xl bg-[#E7F6EF] px-4 py-6 text-center text-sm font-medium text-[#0E7A55]">ไม่มีโครงการที่เลยกำหนดส่ง</p>
            @endif
        </section>
    </div>
</div>
