{{-- The project page's "ข้อมูลโครงการ" tab: project details (every field, grouped) + production progress, the alerts
     that need following up, and the cabinet / MO table. Data from App\Services\ProjectOverview ($overview). --}}
@php
    $s = $overview['summary'];
    $baht = fn ($v) => $v !== null ? number_format((float) $v, 2) : null;
    $tones = [
        'red' => ['btn' => 'text-red-600', 'ring' => 'ring-red-200', 'dot' => 'bg-red-500'],
        'orange' => ['btn' => 'text-orange-600', 'ring' => 'ring-orange-200', 'dot' => 'bg-orange-500'],
        'blue' => ['btn' => 'text-blue-600', 'ring' => 'ring-blue-200', 'dot' => 'bg-blue-500'],
        'gray' => ['btn' => 'text-slate-600', 'ring' => 'ring-slate-200', 'dot' => 'bg-slate-400'],
    ];
    $canContact = auth()->user()->allows('data.contact');   // Settings > สิทธิ์การใช้งาน
    $canFinance = auth()->user()->allows('data.finance');
    $daysLeft = $project->isLocked() ? null : $project->days_remaining;
    // รอฝ่ายขายแจ้งกำหนดส่ง (ProjectDates): in place of the countdown, and its two ways out - the dates came / the old ones stand
    $pendingTools = '';
    if ($project->isDuePending()) {
        $daysLeft = null;
        $pendingTools = ' <span class="ml-1 whitespace-nowrap rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-800">'.e($project->duePendingLabel()).'</span>'
            .'<span class="mt-1 block text-xs text-slate-600">ตั้งแต่ '.$project->due_pending_at->thaiDate().' · ยังไม่ต้องลงวัน</span>'
            .(auth()->user()->canDispatchWork() ? '<span class="mt-1 flex flex-wrap gap-1.5">'
                .'<button type="button" x-on:click="$dispatch(\'open-modal\', \'project-dates\')" class="rounded-lg bg-indigo-700 px-2.5 py-1 text-xs font-semibold text-white hover:bg-indigo-800">ได้วันแล้ว · ลงวันใหม่</button>'
                .'<form method="POST" action="'.e(route('projects.dates.pending', $project)).'" class="inline">'.csrf_field().'<input type="hidden" name="action" value="cancel">'
                .'<button type="submit" class="rounded-lg px-2 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-100">ยกเลิกการรอ · ใช้วันเดิม</button></form>'
                .'</span>' : '');
    }
    // เลื่อน / แก้วัน (App\Services\ProjectDates): how many rounds, beside the due date, and the button for Admin / PM (windows: _dates)
    $dates = app(\App\Services\ProjectDates::class)->summary($project);
    $canDates = auth()->user()->canDispatchWork() && ! $project->isLocked();
    $open = fn (string $modal) => 'x-on:click="$dispatch(\'open-modal\', \''.$modal.'\')"';
    $dateTools = ($dates['rounds']
            ? ' <button type="button" '.$open('project-date-history').' class="ml-1 mt-1 inline-block max-w-full rounded-full bg-orange-100 text-left px-2 py-0.5 text-xs font-bold text-orange-800 hover:bg-orange-200">เลื่อนแล้ว '
                .$dates['rounds'].' ครั้ง'.($dates['days'] ? ' · '.($dates['days'] > 0 ? '+' : '').$dates['days'].' วัน' : '').' ›</button>'
            : ($dates['changes']->isNotEmpty() ? ' <button type="button" '.$open('project-date-history').' class="ml-1 text-xs font-semibold text-blue-700 hover:underline">ประวัติ ›</button>' : ''))
        .($canDates && $project->due_date ? ' <button type="button" '.$open('project-dates').' class="ml-1 mt-1 inline-block rounded-lg border border-slate-300 bg-white px-2 py-0.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">เลื่อน / แก้วัน</button>' : '');

    // [label, value (null = not filled in yet), extra dd classes]; the form's fields, grouped. Legacy fields the form no
    // longer asks for (ใบสั่งขาย, เจ้าของโครงการ) appear only when an older project still has a value.
    $groups = [
        'โครงการ' => [
            ['เลขที่โครงการ', e($project->project_no), 'font-semibold'],
            ['ชื่อโครงการ', e($project->project_name), 'font-semibold'],
            ['ลูกค้า', $project->customer_name ? e($project->customer_name) : null, ''],
            ['สถานะ', 'status', 'badge'],   // drawn with the status / priority components below
            ['ประเภทงาน', $project->jobType ? e($project->jobType->name) : null, ''],
            ['คำอธิบาย', $project->description ? nl2br(e($project->description)) : null, 'whitespace-normal'],
        ],
        'กำหนดการ' => array_values(array_filter([
            ['วันเริ่ม', $project->start_date?->thaiDate(), 'tabular-nums'],
            ['วันครบกำหนด', $project->due_date ? ($project->isDuePending() ? '' : $project->due_date->thaiDate()).($daysLeft !== null
                ? ' <span class="ml-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold '.($daysLeft < 0 ? 'bg-red-100 text-red-700' : ($daysLeft <= 7 ? 'bg-orange-100 text-orange-700' : 'bg-slate-100 text-slate-600')).'">'
                    .($daysLeft < 0 ? 'เลยกำหนด '.abs($daysLeft).' วัน' : ($daysLeft === 0 ? 'ครบกำหนดวันนี้' : 'เหลือ '.$daysLeft.' วัน')).'</span>' : '').$pendingTools.$dateTools : null, 'tabular-nums'],
            $dates['rounds'] && $dates['firstDue'] ? ['วันครบกำหนดตอนแรก', '<span class="text-slate-500 line-through">'.$dates['firstDue']->thaiDate().'</span>', 'tabular-nums'] : null,
            ['วันทำงานคงเหลือ', $s['workingDaysLeft'] !== null ? $s['workingDaysLeft'].' วัน (จ.–ศ.)' : '-', ''],   // worked out from the due date, never typed in
        ])),
        'ทีมและการติดต่อ' => array_values(array_filter([
            ['ผู้จัดการโครงการ (PM)', $project->projectManager ? e($project->projectManager->name) : null, ''],
            ['พนักงานขาย', $project->sales_person ? e($project->sales_person) : null, ''],
            $project->project_owner ? ['เจ้าของโครงการ', e($project->project_owner), ''] : null,
            $canContact ? ['เบอร์ติดต่อลูกค้า', $project->customer_phone
                ? '<a href="tel:'.e(preg_replace('/[^0-9+]/', '', $project->customer_phone)).'" class="text-blue-600 hover:underline">'.e($project->customer_phone).'</a>' : null, ''] : null,
        ])),
        'สัญญาและการเงิน' => array_values(array_filter([
            ['เลขที่ใบสั่งซื้อ (PO)', $project->po_no ? e($project->po_no) : null, ''],
            $project->sales_order_no ? ['ใบสั่งขาย / อ้างอิง', e($project->sales_order_no), ''] : null,
            ['เงื่อนไขการชำระ', $project->payment_terms ? e($project->payment_terms) : null, ''],
            $canFinance ? ['มูลค่างาน (ก่อน VAT)', $baht($project->contract_value), 'tabular-nums'] : null,
            $canFinance && $project->contract_value !== null ? ['VAT 7%', $baht($project->vatAmount()), 'tabular-nums'] : null,
            $canFinance && $project->contract_value !== null ? ['มูลค่างานรวม VAT 7%', $baht($project->contractValueWithVat()), 'font-semibold tabular-nums text-ae-navy'] : null,
            $canFinance ? ['Margin', $project->margin !== null ? $baht($project->margin).($project->marginPercent() !== null ? ' · '.$project->marginPercent().' %' : '') : null, 'tabular-nums'] : null,
        ])),
    ];

    $pace = ['on_track' => ['ทันตามเวลา', 'text-emerald-700'], 'watch' => ['เฝ้าระวัง', 'text-orange-600'], 'behind' => ['ช้ากว่าเวลา', 'text-red-600']][$s['pace']] ?? null;
    $statusBar = array_filter([
        ['เสร็จแล้ว', $s['completed'], 'bg-emerald-500'],
        ['กำลังผลิต', $s['inProgress'], 'bg-blue-400'],
        ['พักไว้', $s['onHold'], 'bg-amber-400'],
        ['ยังไม่เริ่ม', $s['notStarted'], 'bg-slate-300'],
    ], fn ($b) => $b[0] !== 'พักไว้' || $b[1]);

    $alerts = collect($overview['alerts']);
    $quiet = $alerts->where('count', 0)->pluck('title');
@endphp

<div class="space-y-4" x-data="pmoProjectInfo(@js($overview['rows']))" @keydown.escape.window="alert = null">

    {{-- ===== 1. ข้อมูลโครงการ (40%) + ภาพรวมการผลิตกับสถานะโครงการ (60%) ===== --}}
    @php
        // ข้อมูลโครงการ: every field of the project on one card (no folded / full views), in three columns. The project's
        // number, name, customer and status are in its header, so they are not repeated; fields that belong together share
        // a row (the due date with the days left and the first due date, the value with its VAT).
        $byLabel = collect($groups)->flatten(1)->keyBy(0);
        $sizes = $project->cabinets()->whereNotNull('size')->where('size', '!=', '')->pluck('size')->countBy()->sortDesc();
        $muted = fn (string $t) => ' <span class="font-normal text-slate-600">'.$t.'</span>';
        $cols = [
            'งาน' => array_values(array_filter([
                ['เลขที่ PO', $byLabel['เลขที่ใบสั่งซื้อ (PO)'][1] ?? null, ''],
                $project->payment_terms ? ['เงื่อนไขการชำระ', e($project->payment_terms), ''] : null,
                $project->sales_order_no ? ['ใบสั่งขาย / อ้างอิง', e($project->sales_order_no), ''] : null,
                ['ประเภทงาน', $byLabel['ประเภทงาน'][1] ?? null, ''],
                ['ตู้', $s['total'].' ตู้'.($sizes->isNotEmpty() ? $muted('· '.e($sizes->keys()->first()).($sizes->count() > 1 ? ' และอีก '.($sizes->count() - 1).' ขนาด' : '')) : $muted('· ยังไม่ระบุขนาด')), 'tabular-nums'],
            ])),
            'วันที่และมูลค่า' => array_values(array_filter([
                ['วันเริ่ม', $byLabel['วันเริ่ม'][1] ?? null, 'tabular-nums'],
                ['วันครบกำหนด', $project->due_date ? ($byLabel['วันครบกำหนด'][1] ?? '')
                    .($dates['rounds'] && $dates['firstDue'] ? ' <span class="ml-1 whitespace-nowrap text-xs font-normal text-slate-600" title="วันครบกำหนดตอนแรก">ตอนแรก <span class="line-through">'.$dates['firstDue']->thaiDate().'</span></span>' : '')
                    .($s['workingDaysLeft'] !== null ? '<span class="mt-0.5 block text-xs font-normal text-slate-600">วันทำงานคงเหลือ '.$s['workingDaysLeft'].' วัน (จ.–ศ.)</span>' : '') : null, 'tabular-nums'],
                $canFinance ? ['มูลค่างาน (ก่อน VAT)', $project->contract_value !== null
                    ? $baht($project->contract_value).'<span class="mt-0.5 block text-xs font-normal text-slate-600" title="VAT 7% '.$baht($project->vatAmount()).'">รวม VAT 7% <b class="font-semibold text-ae-navy">'.$baht($project->contractValueWithVat()).'</b></span>' : null, 'tabular-nums'] : null,
                $canFinance ? $byLabel['Margin'] : null,
            ])),
            'ติดต่อ' => array_values(array_filter([
                ['PM', $byLabel['ผู้จัดการโครงการ (PM)'][1] ?? null, ''],
                ['พนักงานขาย', $byLabel['พนักงานขาย'][1] ?? null, ''],
                isset($byLabel['เจ้าของโครงการ']) ? $byLabel['เจ้าของโครงการ'] : null,
                $canContact ? ['เบอร์ลูกค้า', $byLabel['เบอร์ติดต่อลูกค้า'][1] ?? null, 'tabular-nums'] : null,
            ])),
        ];

        // สถานะโครงการ: เริ่ม → ผลิต (the bar) → FAT → ส่งมอบ → ปิดโครงการ, from what the page already has
        // dates as the rest of the app writes them (Planning's dd/mm): "17/11", another year "15/02/70"
        $short = fn ($d) => $d ? $d->format('d/m').($d->year !== today()->year ? '/'.substr((string) ($d->year + 543), 2) : '') : '';
        // "17–18/11", "30/11 – 02/12", one day: "17/11"
        $range = fn ($a, $b) => ! $b || $b->lte($a) ? $short($a) : ($a->format('Y-m') === $b->format('Y-m') ? $a->format('d').'–'.$short($b) : $short($a).' – '.$short($b));
        $tlFat = $hdrFat ?? $project->fats()->get()->last();
        $tlRounds = $hdrRounds ?? $project->deliveries()->where('status', '!=', 'draft')->get();
        $tlCan = $hdrCanAppt ?? (auth()->user()->canDispatchWork() && ! $project->isLocked());
        $barColor = ['watch' => 'bg-orange-500', 'behind' => 'bg-red-500'][$s['pace']] ?? 'bg-emerald-600';
        $barText = ['watch' => 'text-orange-700', 'behind' => 'text-red-700'][$s['pace']] ?? 'text-emerald-700';
        // FAT: state (done / next / wait / todo / late / fail), the words under it, what a click does
        $fatLeft = $tlFat ? (int) today()->diffInDays($tlFat->lastDay(), false) : null;
        $fatNode = match (true) {
            ! $tlFat => ['todo', 'FAT', $tlCan ? 'ลงวัน' : 'ยังไม่ได้นัด', $tlCan ? 'edit' : null],
            $tlFat->status === 'passed' => ['done', 'FAT ผ่าน', $range($tlFat->fat_date, $tlFat->lastDay()), 'show'],
            $tlFat->status === 'failed' => ['fail', 'FAT ไม่ผ่าน', 'รอบที่ '.$tlFat->round.' · นัดรอบใหม่', $tlCan ? 'edit' : 'show'],
            $fatLeft < 0 => ['late', 'FAT', 'เลย '.abs($fatLeft).' วัน · ยังไม่บันทึกผล', $tlCan ? 'edit' : 'show'],
            default => ['wait', 'FAT', $range($tlFat->fat_date, $tlFat->lastDay()), 'show'],
        };
        $sent = $tlRounds->where('status', 'delivered');
        $open = $tlRounds->where('status', '!=', 'delivered');
        $nextRound = $open->sortBy(fn ($d) => $d->firstDay()->format('Ymd'))->first();
        $dlNode = match (true) {
            $tlRounds->isEmpty() => ['todo', 'ส่งมอบ', $tlCan ? 'ลงวัน' : 'ยังไม่ได้นัด', $tlCan ? 'new' : null],
            $open->isEmpty() => ['done', 'ส่งมอบครบ', $tlRounds->count().' รอบ', 'show'],
            $nextRound->isOverdue() => ['late', 'ส่งมอบ', 'รอบ '.$nextRound->round.' เลยวันส่ง', 'show'],
            default => [$sent->isNotEmpty() ? 'part' : 'wait', 'ส่งมอบ',
                $sent->isNotEmpty() ? 'ส่งแล้ว '.$sent->flatMap(fn ($d) => $d->cabinet_ids)->unique()->count().' / '.$s['total'].' ตู้'
                    : $range($tlRounds->min(fn ($d) => $d->firstDay()), $tlRounds->max(fn ($d) => $d->lastDay())).($tlRounds->count() > 1 ? ' · '.$tlRounds->count().' รอบ' : ''), 'show'],
        };
        // "ถัดไป": whichever still to come is sooner (a delivery may well come before the FAT)
        $fatSoon = $fatNode[0] === 'wait' ? $tlFat->fat_date : null;
        $dlSoon = in_array($dlNode[0], ['wait', 'part'], true) ? $nextRound?->firstDay() : null;
        if ($fatSoon && (! $dlSoon || $fatSoon->lte($dlSoon))) {
            $fatNode[4] = true;
        } elseif ($dlSoon) {
            $dlNode[4] = true;
        }
        $closed = $project->status === 'completed';
        $dot = function (string $state, ?string $text = null, bool $next = false) {
            $check = '<svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12l5 5L20 7"/></svg>';
            return match ($state) {
                'done' => '<span class="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-600 text-white">'.$check.'</span>',
                'late', 'fail' => '<span class="flex h-7 w-7 items-center justify-center rounded-full border-[3px] border-red-600 bg-white text-xs font-bold text-red-700">'.e($text ?? '!').'</span>',
                'todo' => '<span class="block h-6 w-6 rounded-full border-2 border-dashed border-slate-400 bg-white"></span>',
                default => $next ? '<span class="flex h-7 w-7 items-center justify-center rounded-full border-[3px] border-blue-600 bg-white text-xs font-bold text-blue-700">'.($text ? e($text) : '<span class="block h-2.5 w-2.5 rounded-full bg-blue-600"></span>').'</span>'
                    : '<span class="flex h-6 w-6 items-center justify-center rounded-full border-2 border-slate-300 bg-white text-[11px] font-bold text-slate-600">'.e($text ?? '').'</span>',
            };
        };
        $tone = fn (array $n) => match ($n[0]) { 'done' => 'text-emerald-700', 'late', 'fail' => 'text-red-700', 'todo' => 'text-slate-500', default => ! empty($n[4]) ? 'text-blue-700' : 'text-slate-800' };
        $fatText = $fatNode[0] === 'fail' ? (string) ($tlFat->round + 1) : null;
        $dlText = $dlNode[0] === 'part' ? $sent->count().'/'.$tlRounds->count() : null;
        $go = fn (array $n, string $event) => $n[3] ? '@click="$dispatch(\''.$event.'\', \''.$n[3].'\')"' : '';
    @endphp
    <section class="grid gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div class="flex min-w-0 flex-col rounded-[14px] border border-slate-200 bg-white px-5 pb-4 pt-4" aria-label="ข้อมูลโครงการ">
            <h3 class="text-[15px] font-bold text-ae-navy">ข้อมูลโครงการ</h3>
            <div class="mt-2 grid gap-x-6 gap-y-4 md:grid-cols-3">
                @foreach ($cols as $title => $rows)
                    <div class="min-w-0">
                        <h4 class="border-b border-slate-200 pb-1 text-[13px] font-bold text-slate-600">{{ $title }}</h4>
                        <dl class="text-sm">
                            @foreach ($rows as [$label, $value, $class])
                                <div class="border-b border-slate-100 py-2 last:border-0">
                                    <dt class="text-[13px] text-slate-600">{{ $label }}</dt>
                                    <dd class="min-w-0 break-words {{ $value === null ? 'text-slate-500' : 'font-semibold text-slate-900 '.$class }}">{!! $value ?? 'ยังไม่ระบุ' !!}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endforeach
            </div>
            {{-- คำอธิบาย: under everything, at reading size; a long one shows 3 lines until "อ่านต่อ" --}}
            <div class="mt-3 border-t border-slate-200 pt-2.5" x-data="{ all: false, long: false }" x-init="$nextTick(() => { long = $refs.text && $refs.text.scrollHeight > $refs.text.clientHeight + 2 })">
                <h4 class="text-[13px] font-bold text-slate-600">คำอธิบาย</h4>
                @if ($project->description)
                    <p x-ref="text" class="mt-1 whitespace-pre-line text-[15px] leading-relaxed text-slate-800" :class="all ? '' : 'line-clamp-3'">{{ $project->description }}</p>
                    <button type="button" x-show="long || all" x-cloak @click="all = ! all" :aria-expanded="all" class="mt-1 text-[13px] font-semibold text-blue-700 hover:underline" x-text="all ? 'ย่อ' : 'อ่านต่อ'">อ่านต่อ</button>
                @else
                    <p class="mt-1 text-sm text-slate-500">ยังไม่มีคำอธิบาย @editplan @unless ($project->isLocked())<a href="{{ route('projects.edit', $project) }}" class="font-semibold text-blue-700 hover:underline">เพิ่มที่ แก้ไขโครงการ</a>@endunless @endeditplan</p>
                @endif
            </div>
        </div>

        @php
            // the pace as a word with its colour and icon (never the colour alone), and how far from "on time" it is
            $paceTag = ['on_track' => ['ทันตามเวลา', 'bg-emerald-50 text-emerald-800', 'M5 12l5 5L20 7'],
                'watch' => ['เฝ้าระวัง', 'bg-orange-50 text-orange-800', 'M12 7v5l3 2M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z'],
                'behind' => ['ช้ากว่าเวลา', 'bg-red-50 text-red-800', 'M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z']][$s['pace']] ?? null;
            $gap = $s['timeElapsed'] !== null && $s['progress'] < 100 ? $s['timeElapsed'] - $s['progress'] : null;
        @endphp
        <div class="flex min-w-0 flex-col gap-4 rounded-[14px] border border-slate-200 bg-white px-5 pb-4 pt-4" aria-label="ภาพรวมการผลิต">
            <div>
                <div class="flex items-start justify-between gap-3">
                    <h3 class="text-[15px] font-bold text-ae-navy">ความคืบหน้าการผลิต</h3>
                    @if ($paceTag)
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-[13px] font-bold {{ $paceTag[1] }}">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $paceTag[2] }}"/></svg>{{ $paceTag[0] }}</span>
                    @endif
                </div>
                <div class="mt-1 flex items-end justify-between gap-3">
                    <p class="text-[34px] font-bold leading-none tracking-tight tabular-nums text-ae-navy">{{ $s['progress'] }}<span class="text-xl">%</span></p>
                    <p class="pb-0.5 text-right text-[13px] text-slate-600">ตู้ผลิตเสร็จ <b class="text-base tabular-nums text-slate-900">{{ $s['completed'] }}</b> / {{ $s['total'] }}</p>
                </div>
                {{-- the one bar: what is made; the dark tick = where it should be by now, at an even pace from start to due --}}
                <div class="relative mt-3 h-2.5 rounded-full bg-slate-100" role="img"
                     aria-label="ผลิตแล้ว {{ $s['progress'] }}%{{ $s['timeElapsed'] !== null ? ' ตามเวลาควรได้ '.$s['timeElapsed'].'%' : '' }}">
                    <div class="h-2.5 rounded-full {{ $barColor }}" style="width: {{ $s['progress'] }}%"></div>
                    @if ($gap !== null)
                        <div class="absolute -top-1 h-[18px] w-0.5 -translate-x-1/2 rounded bg-slate-900" style="left: {{ $s['timeElapsed'] }}%"></div>
                    @endif
                </div>
                <p class="mt-1.5 flex flex-wrap justify-between gap-x-3 text-[13px] text-slate-600">
                    @if ($gap !== null)
                        <span><span class="mr-1 inline-block h-3 w-0.5 translate-y-0.5 rounded bg-slate-900" aria-hidden="true"></span>ตามเวลาควรได้ {{ $s['timeElapsed'] }}%</span>
                        <span class="font-semibold {{ $gap > 0 ? $barText : 'text-emerald-700' }}">{{ $gap > 0 ? 'ช้ากว่า '.$gap.'%' : ($gap < 0 ? 'เร็วกว่า '.abs($gap).'%' : 'ตรงตามเวลา') }}</span>
                    @elseif ($s['progress'] >= 100)
                        <span class="font-semibold text-emerald-700">ผลิตครบทุกตู้</span>
                    @else
                        <span>ยังไม่มีวันเริ่ม / วันครบกำหนด จึงยังเทียบกับเวลาไม่ได้</span>
                    @endif
                </p>
                {{-- where the cabinets are --}}
                <ul class="mt-3 flex flex-wrap gap-1.5 text-[13px]" aria-label="ตู้แยกตามสถานะ">
                    @foreach ([['กำลังทำ', $s['inProgress'], 'bg-blue-600'], ['ยังไม่เริ่ม', $s['notStarted'], 'bg-slate-400'], ['พักไว้', $s['onHold'], 'bg-amber-500']] as [$label, $n, $color])
                        @continue($label === 'พักไว้' && ! $n)
                        <li class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 px-2.5 py-0.5 {{ $n ? 'text-slate-800' : 'text-slate-500' }}">
                            <span class="h-2 w-2 rounded-full {{ $color }}" aria-hidden="true"></span>{{ $label }} <b class="tabular-nums">{{ $n }}</b></li>
                    @endforeach
                </ul>
            </div>

            <div class="border-t border-slate-100 pt-3.5">
                <h4 class="mb-2.5 text-[13px] font-bold text-slate-600">สถานะโครงการ</h4>
                <ol aria-label="สถานะโครงการ">
                    <li class="flex gap-3"><span class="flex flex-col items-center">{!! $dot($project->start_date && $project->start_date->lte(today()) ? 'done' : 'wait') !!}<span class="w-0.5 flex-1 bg-slate-200"></span></span>
                        <span class="pb-3 text-[13px]"><b class="block">เริ่มโครงการ</b><span class="text-slate-600 tabular-nums">{{ $short($project->start_date) ?: 'ยังไม่ระบุ' }}</span></span></li>
                    <li class="flex gap-3"><span class="flex flex-col items-center">{!! $dot($s['progress'] >= 100 ? 'done' : 'wait') !!}<span class="w-0.5 flex-1 {{ $s['progress'] >= 100 ? 'bg-emerald-600' : 'bg-slate-200' }}"></span></span>
                        <span class="pb-3 text-[13px]"><b class="block {{ $s['progress'] >= 100 ? 'text-emerald-700' : $barText }}">{{ $s['progress'] >= 100 ? 'ผลิตครบ' : 'กำลังผลิต' }}</b><span class="text-slate-600 tabular-nums">{{ $s['progress'] }}% · ตู้เสร็จ {{ $s['completed'] }} / {{ $s['total'] }}</span></span></li>
                    <li class="flex gap-3"><span class="flex flex-col items-center">{!! $dot($fatNode[0], $fatText, ! empty($fatNode[4])) !!}<span class="w-0.5 flex-1 {{ $fatNode[0] === 'done' ? 'bg-emerald-600' : 'bg-slate-200' }}"></span></span>
                        <button type="button" {!! $go($fatNode, 'pmo-fat') !!} @disabled(! $fatNode[3]) class="-mx-1.5 -mt-1 mb-2 min-h-11 flex-1 rounded-lg px-1.5 pt-1 text-left text-[13px] enabled:hover:bg-slate-50 disabled:cursor-default"><b class="block {{ $tone($fatNode) }}">{{ $fatNode[1] }}{{ ! empty($fatNode[4]) ? ' · ถัดไป' : '' }}</b><span class="{{ $fatNode[3] && $fatNode[0] === 'todo' ? 'font-semibold text-blue-700' : 'text-slate-600' }} tabular-nums">{{ $fatNode[2] }}</span></button></li>
                    <li class="flex gap-3"><span class="flex flex-col items-center">{!! $dot($dlNode[0], $dlText, ! empty($dlNode[4])) !!}<span class="w-0.5 flex-1 {{ $dlNode[0] === 'done' ? 'bg-emerald-600' : 'bg-slate-200' }}"></span></span>
                        <button type="button" {!! $go($dlNode, 'pmo-delivery') !!} @disabled(! $dlNode[3]) class="-mx-1.5 -mt-1 mb-2 min-h-11 flex-1 rounded-lg px-1.5 pt-1 text-left text-[13px] enabled:hover:bg-slate-50 disabled:cursor-default"><b class="block {{ $tone($dlNode) }}">{{ $dlNode[1] }}{{ ! empty($dlNode[4]) ? ' · ถัดไป' : '' }}</b><span class="{{ $dlNode[3] ? 'text-blue-700' : 'text-slate-600' }} tabular-nums">{{ $dlNode[2] }}</span></button></li>
                    <li class="flex gap-3">{!! $dot($closed ? 'done' : 'wait') !!}
                        <span class="text-[13px]"><b class="block {{ $closed ? 'text-emerald-700' : '' }}">ปิดโครงการ</b><span class="text-slate-600 tabular-nums">{{ $closed ? 'ปิดแล้ว' : ($project->due_date ? 'ครบกำหนด '.$short($project->due_date) : 'ยังไม่มีวันครบกำหนด') }}</span></span></li>
                </ol>
            </div>
        </div>
    </section>

    {{-- work whose checklist is all ticked but never closed (ticked for the department): one line; "ดูทั้งหมด" opens the
         list and the button that closes them in one go, then the project can close --}}
    @if ($overview['ticked'])
        <section aria-label="Checklist ครบแต่ยังไม่ได้ปิดงาน" class="rounded-xl bg-orange-50" x-data="{ list: false }">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2 pl-3 pr-2">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-orange-600 text-white" aria-hidden="true">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" viewBox="0 0 24 24"><path d="M12 8v5M12 16.5h.01"/></svg></span>
                <p class="min-w-0 flex-1 text-sm text-orange-950">Checklist ครบแล้วแต่ยังไม่ได้ปิดใบงาน <b class="text-orange-800">{{ count($overview['ticked']) }} รายการ</b></p>
                <button type="button" @click="list = ! list" :aria-expanded="list" aria-controls="ticked-list"
                        class="rounded-lg bg-white px-3 py-1.5 text-[13px] font-semibold text-orange-800 hover:bg-orange-100" x-text="list ? 'ซ่อน' : 'ดูทั้งหมด'">ดูทั้งหมด</button>
            </div>
            <div id="ticked-list" x-show="list" x-cloak class="flex flex-wrap items-start gap-x-4 gap-y-2 border-t border-orange-200/70 px-4 py-3">
                <div class="min-w-0 flex-1">
                    <p class="text-sm text-orange-900">แผนกยังไม่ได้กดรับงาน / เสร็จงาน (เช่น มีคนติ๊กแทน) · ปิดงานเหล่านี้แล้วจึงปิดโครงการได้</p>
                    <ul class="mt-1.5 flex flex-wrap gap-1.5 text-[13px]">
                        @foreach (array_slice($overview['ticked'], 0, 8) as $t)
                            <li class="rounded-full bg-white px-2 py-0.5 text-orange-950 ring-1 ring-orange-200">{{ $t['mo'] }} · {{ $t['name'] }} · {{ $t['dept'] }} <span class="text-orange-800">({{ $t['state'] }})</span></li>
                        @endforeach
                        @if (count($overview['ticked']) > 8)<li class="px-1 py-0.5 text-orange-900">และอีก {{ count($overview['ticked']) - 8 }} งาน</li>@endif
                    </ul>
                </div>
                <form method="POST" action="{{ route('projects.finish-ticked', $project) }}" onsubmit="return confirm('ปิดงาน {{ count($overview['ticked']) }} งานที่ติ๊กครบแล้ว แทนแผนก?\n(รับงาน → เริ่มงาน → เสร็จงาน บันทึกว่าทำแทนโดยคุณ)')">
                    @csrf
                    <button class="inline-flex h-10 items-center rounded-lg bg-orange-600 px-4 text-sm font-semibold text-white hover:bg-orange-700">ปิดงานเหล่านี้ (ทำแทนแผนก)</button>
                </form>
            </div>
        </section>
    @endif

    {{-- every department's work is done: Admin / PM are asked to close the project (Completed locks it, so never automatic) --}}
    @if ($ready = $overview['readyToClose'])
        <section aria-label="งานครบทุกแผนกแล้ว" class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white" aria-hidden="true">✓</span>
            <div class="min-w-0 flex-1">
                <p class="font-bold text-emerald-900">งานครบทุกแผนกแล้ว ({{ $ready['done'] }} งาน) · ปิดโครงการเป็น Completed ได้</p>
                <p class="text-sm text-emerald-800">ปิดแล้วจะแก้งานและ Checklist ไม่ได้ จนกว่าจะเปลี่ยนสถานะกลับ
                    @if ($ready['unassigned'])<span class="font-semibold"> · ยังมีงานที่ไม่ได้จ่าย {{ $ready['unassigned'] }} งาน</span>@endif
                    @if ($ready['fat'])<span class="font-semibold"> · {{ $ready['fat'] }}</span>@endif
                </p>
            </div>
            <form method="POST" action="{{ route('projects.complete', $project) }}" onsubmit="return confirm('ปิดโครงการ {{ $project->project_no }} เป็น Completed?\nหลังปิดจะแก้งานและ Checklist ไม่ได้ จนกว่าจะเปลี่ยนสถานะกลับ')">
                @csrf
                <button class="inline-flex h-10 items-center rounded-lg bg-emerald-700 px-4 text-sm font-semibold text-white hover:bg-emerald-800">ปิดโครงการ</button>
            </form>
        </section>
    @endif

    {{-- วัน FAT: the latest round, its history, and (Admin / PM) the form --}}
    @include('projects.partials._dates', ['subject' => $project])
    @include('projects.partials._fat')
    @include('projects.partials._delivery')

    {{-- ===== 2. what needs following up: worded chips, each opening a short list; the empty ones are listed quietly ===== --}}
    <section aria-label="เรื่องที่ต้องตาม" class="flex flex-wrap items-center gap-2" @click.outside="alert = null">
        <span class="mr-1 text-sm font-bold text-slate-600">ต้องตาม</span>
        @foreach ($alerts->where('count', '>', 0) as $a)
            @php $t = $tones[$a['tone']]; @endphp
            <div class="relative">
                <button type="button" @click="alert = alert === '{{ $a['key'] }}' ? null : '{{ $a['key'] }}'"
                        :aria-expanded="alert === '{{ $a['key'] }}'" aria-controls="alert-{{ $a['key'] }}"
                        class="inline-flex min-h-9 items-center gap-2 rounded-full border border-slate-200 bg-white py-1 pl-3 pr-2.5 text-sm text-slate-700 shadow-sm hover:border-slate-400"
                        :class="alert === '{{ $a['key'] }}' && 'ring-2 {{ $t['ring'] }}'">
                    <span class="h-2 w-2 rounded-full {{ $t['dot'] }}"></span>
                    <strong class="tabular-nums {{ $t['btn'] }}">{{ $a['count'] }}</strong>{{ $a['title'] }}
                    <svg class="h-3 w-3 opacity-60" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
                </button>
                <div id="alert-{{ $a['key'] }}" x-show="alert === '{{ $a['key'] }}'" x-cloak x-transition.opacity
                     class="absolute left-0 z-30 mt-2 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-slate-200 bg-white text-sm shadow-xl">
                    <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5 font-semibold text-slate-800"><span class="h-2 w-2 rounded-full {{ $t['dot'] }}"></span>{{ $a['title'] }} ({{ $a['count'] }})</div>
                    <ul class="max-h-72 divide-y divide-slate-50 overflow-y-auto">
                        @foreach ($a['items'] as $item)
                            <li>
                                <a href="{{ $item['url'] }}" class="flex items-start justify-between gap-3 px-4 py-2 hover:bg-slate-50 focus:bg-slate-50 focus:outline-none">
                                    <span class="min-w-0">
                                        <span class="flex items-center gap-1.5 font-medium text-slate-800">@if ($item['color'])<span class="h-2 w-2 shrink-0 rounded-full" style="background: {{ $item['color'] }}"></span>@endif<span class="truncate">{{ $item['label'] }}</span></span>
                                        <span class="block truncate text-xs text-slate-500">{{ $item['sub'] }}</span>
                                    </span>
                                    @if ($item['note'])<span class="shrink-0 whitespace-nowrap text-xs font-semibold {{ $t['btn'] }}">{{ $item['note'] }}</span>@endif
                                </a>
                            </li>
                        @endforeach
                        @foreach ($a['departments'] ?? [] as $d)
                            <li class="flex items-center justify-between gap-3 px-4 py-2">
                                <span class="min-w-0">
                                    <span class="flex items-center gap-1.5 font-medium text-slate-800"><span class="h-2 w-2 shrink-0 rounded-full" style="background: {{ $d['color'] }}"></span><span class="truncate">{{ $d['name'] }}</span></span>
                                    <span class="block text-xs text-slate-500">รอรับ {{ $d['count'] }} งาน · นานสุด {{ $d['days'] }} วัน</span>
                                </span>
                                @if ($overview['canDispatch'])
                                    <button type="button" onclick="pmoRemind('{{ route('cabinet-subtasks.remind') }}', @js($d['ids']), this)"
                                            class="shrink-0 rounded-md border border-blue-300 px-2 py-1 text-xs font-semibold text-blue-700 hover:bg-blue-50 disabled:opacity-50">ทวงถาม</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($a['filter'] || $a['count'] > count($a['items']) && empty($a['departments']))
                        <div class="border-t border-slate-100 px-4 py-2 text-right">
                            @if ($a['filter'])
                                <button type="button" @click="risk = '{{ $a['filter'] }}'; filters = true; page = 1; alert = null; $nextTick(() => $refs.table.scrollIntoView({ behavior: 'smooth', block: 'start' }))"
                                        class="text-xs font-semibold text-blue-600 hover:underline">ดูทั้งหมดในตาราง →</button>
                            @else
                                <span class="text-xs text-slate-500">แสดง {{ count($a['items']) }} จาก {{ $a['count'] }} รายการ</span>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
        @if ($quiet->isNotEmpty())
            <span class="text-xs text-slate-500">ไม่มี: {{ $quiet->implode(' · ') }}</span>
        @endif
    </section>

    {{-- ===== 3. cabinet / MO table ===== --}}
    <section x-ref="table" id="cabinets-section" aria-label="ตู้ / ใบสั่งผลิต" class="scroll-mt-20 rounded-[14px] border border-slate-200 bg-white">
        <div class="flex flex-wrap items-center gap-2 px-5 py-3.5">
            <h3 class="mr-2 text-[15px] font-bold text-ae-navy">ตู้ / ใบสั่งผลิต <span class="font-medium text-slate-500">{{ $overview['cabinetCount'] }}</span></h3>
            <div class="relative min-w-0 flex-1 basis-48 sm:max-w-xs">
                <label for="cab-search" class="sr-only">ค้นหาตู้</label>
                <svg class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
                <input id="cab-search" type="search" x-model="q" @input="page = 1" placeholder="ค้นหา MO / ชื่อตู้" class="w-full rounded-lg border-slate-300 py-1.5 pl-8 text-sm">
            </div>
            <button type="button" @click="filters = ! filters" :aria-expanded="filters" aria-controls="cab-filters"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"/></svg>ตัวกรอง
                <span x-show="activeFilters()" x-cloak class="rounded-full bg-blue-600 px-1.5 text-xs font-bold text-white" x-text="activeFilters()"></span>
            </button>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                @php
                    $canStep = $overview['canDispatch'] && $overview['groups'];
                    $canEdit = auth()->user()->canDispatchWork();   // the same check as @editplan
                    // every step a group offers, named with its template when the project mixes templates
                    $steps = $canStep ? collect($overview['groups'])->flatMap(fn ($g) => collect($g['steps'])->map(fn ($st) => ['label' => (count($overview['groups']) > 1 ? ($g['templateName'] ?? 'Template').' · ' : '').$st['label'], 'parts' => $st['parts']]))->values() : [];
                @endphp
                @if ($canStep || $canEdit)
                    <div class="relative" x-data="pmoStepDispatch(@js($steps))">
                        <div x-data="{ menu: false }" @click.outside="menu = false" @keydown.escape="menu = false">
                            <button type="button" @click="menu = ! menu" :aria-expanded="menu" aria-haspopup="true"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                จ่ายงาน / Checklist
                                <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
                            </button>
                            <div x-show="menu" x-cloak x-transition.opacity class="absolute right-0 z-30 mt-1 w-56 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 text-sm shadow-xl">
                                @if ($canStep)
                                    <button type="button" @click="menu = false; open(0)" class="block w-full px-4 py-2 text-left text-slate-700 hover:bg-slate-50">จ่ายงานทั้งขั้น</button>
                                @endif
                                @if ($canEdit)
                                    <button type="button" @click="menu = false; $dispatch('open-modal', 'checklist-bulk')" class="block w-full px-4 py-2 text-left text-slate-700 hover:bg-slate-50">แก้ Checklist ทั้งโครงการ</button>
                                @endif
                            </div>
                        </div>
                        @if ($canStep)
                            @include('projects.partials._step-dispatch')
                        @endif
                    </div>
                @endif
                @editplan
                <button type="button" x-data @click="$dispatch('open-modal', 'add-cabinet')" class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-blue-700">+ เพิ่มตู้</button>
                @endeditplan
            </div>

            <div id="cab-filters" x-show="filters" x-cloak class="flex w-full flex-wrap items-center gap-2 pt-1">
                <label class="sr-only" for="cab-status">สถานะ</label>
                <select id="cab-status" x-model="status" @change="page = 1" class="rounded-lg border-slate-300 py-1.5 text-sm">
                    <option value="">ทุกสถานะ</option>
                    <option value="not_started">ยังไม่เริ่ม</option>
                    <option value="in_progress">กำลังผลิต</option>
                    <option value="completed">เสร็จแล้ว</option>
                    <option value="on_hold">พักไว้</option>
                </select>
                @if (count($overview['types']) > 1)
                    <label class="sr-only" for="cab-type">ประเภท</label>
                    <select id="cab-type" x-model="type" @change="page = 1" class="rounded-lg border-slate-300 py-1.5 text-sm">
                        <option value="">ทุกประเภท</option>
                        @foreach ($overview['types'] as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach
                    </select>
                @endif
                <label class="sr-only" for="cab-risk">ความเสี่ยง</label>
                <select id="cab-risk" x-model="risk" @change="page = 1" class="rounded-lg border-slate-300 py-1.5 text-sm">
                    <option value="">ทุกความเสี่ยง</option>
                    <option value="high">สูง</option>
                    <option value="watch">เฝ้าระวัง</option>
                    <option value="ok">ปกติ</option>
                    <option value="delayed">ตู้ล่าช้า</option>
                    <option value="late-tasks">มีงานเลยกำหนด</option>
                </select>
                <button type="button" x-show="activeFilters()" @click="status = ''; type = ''; risk = ''; page = 1" class="px-2 py-1.5 text-sm font-medium text-blue-600 hover:underline">ล้างตัวกรอง</button>
            </div>
        </div>

        <div class="relative overflow-x-auto">   {{-- relative: keeps the sr-only header label inside the scroll box --}}
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-y border-slate-100 text-left text-xs font-semibold text-slate-600">
                        <th class="w-10 py-2.5 pl-5 pr-0 text-right"><span aria-hidden="true">#</span><span class="sr-only">ลำดับ</span></th>
                        <th class="px-4 py-2">ตู้ @if ($overview['moPrefix'])<span class="font-normal text-slate-400" title="ทุกตู้ขึ้นต้นด้วย {{ $overview['moPrefix'] }}">({{ $overview['moPrefix'] }} …)</span>@endif</th>
                        <th class="px-2 py-2">ความคืบหน้า</th>
                        <th class="hidden px-2 py-2 md:table-cell">ตอนนี้อยู่ขั้น</th>
                        <th class="hidden px-2 py-2 lg:table-cell">FAT</th>
                        <th class="hidden px-2 py-2 sm:table-cell">ส่ง</th>
                        <th class="hidden whitespace-nowrap px-2 py-2 text-right sm:table-cell">งานเลยกำหนด</th>
                        <th class="px-2 py-2"><span class="sr-only">ดำเนินการ</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="r in paged()" :key="r.id">
                        <tr class="cursor-pointer hover:bg-slate-50" @click="if (! $event.target.closest('a, button')) window.location = r.url">
                            <td class="py-3 pl-5 pr-0 text-right align-top text-sm font-semibold tabular-nums text-slate-400" x-text="r.no"></td>
                            <td class="max-w-[15rem] px-4 py-3" :title="r.mo + (r.model ? ' · ' + r.model : '')">
                                <span class="flex items-center gap-2">
                                    <a :href="r.url" class="line-clamp-2 break-words font-semibold leading-snug text-ae-navy hover:underline" x-text="r.site"></a>
                                    <span x-show="r.status === 'on_hold'" class="shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700">พักไว้</span>
                                </span>
                                <div class="line-clamp-2 break-words text-[13px] text-slate-600" x-text="r.model"></div>
                            </td>
                            <td class="px-2 py-3">
                                <div class="flex min-w-[7rem] items-center gap-2">
                                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-1.5 rounded-full bg-emerald-600" :style="'width:' + r.progress + '%'"></div></div>
                                    <span class="w-9 text-right text-[13px] font-bold tabular-nums" :class="r.progress ? 'text-slate-800' : 'text-slate-500'" x-text="r.progress + '%'"></span>
                                </div>
                            </td>
                            <td class="hidden max-w-[16rem] px-2 py-3 md:table-cell">
                                <span class="flex items-center gap-2">
                                    <span class="h-2 w-2 shrink-0 rounded-[3px]" :style="'background:' + r.currentColor"></span>
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-slate-800" x-text="r.current" :title="r.current"></span>
                                        <span class="block truncate text-xs text-slate-500" x-show="r.currentDept" x-text="r.currentDept"></span>
                                    </span>
                                </span>
                            </td>
                            {{-- the latest FAT round the cabinet is in, as the "ส่ง" column: the day, then days to it, or its result (in words) --}}
                            <td class="hidden whitespace-nowrap px-2 py-3 lg:table-cell" :title="r.fat && r.fat.round > 1 ? 'FAT รอบที่ ' + r.fat.round : null">
                                <span class="block tabular-nums text-slate-600" x-text="r.fat ? r.fat.date : '-'"></span>
                                <span x-show="r.fat && r.fat.days !== null" class="text-xs font-semibold" :class="r.fat && dueClass(r.fat.days)" x-text="r.fat && dueLabel(r.fat.days)"></span>
                                <span x-show="r.fat && r.fat.result" class="text-xs font-semibold" :class="r.fat && r.fat.result === 'failed' ? 'text-red-600' : 'text-emerald-700'" x-text="r.fat ? '· ' + r.fat.resultText : ''"></span>
                            </td>
                            <td class="hidden whitespace-nowrap px-2 py-3 sm:table-cell">
                                <span class="block tabular-nums text-slate-600" x-text="r.due || '-'"></span>
                                <span x-show="r.daysLeft !== null" class="text-xs font-semibold" :class="dueClass(r.daysLeft)" x-text="dueLabel(r.daysLeft)"></span>
                            </td>
                            <td class="hidden whitespace-nowrap px-2 py-3 text-right sm:table-cell">
                                <span x-show="r.lateTasks" class="rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-bold text-red-700" x-text="r.lateTasks + ' งาน'"></span>
                                <span x-show="! r.lateTasks" class="text-xs text-slate-500">ไม่มี</span>
                            </td>
                            <td class="whitespace-nowrap py-3 pl-2 pr-5 text-right">
                                <span class="inline-flex items-center gap-1.5">
                                    <a :href="r.url" class="hidden rounded-lg px-2.5 py-1 text-[13px] font-semibold text-blue-700 hover:bg-blue-50 sm:inline-block">ดูรายละเอียด</a>
                                    @editplan
                                    {{-- the cabinet's other actions (its copy window: copy-cabinet-{id}) --}}
                                    {{-- fixed, from the button's place: the table's scroll box would clip it otherwise --}}
                                    <span x-data="{ menu: false, x: 0, y: 0 }" @click.outside="menu = false" @keydown.escape="menu = false" @scroll.window="menu = false" @resize.window="menu = false">
                                        <button type="button" @click="const b = $el.getBoundingClientRect(); x = b.right; y = b.bottom; menu = ! menu" :aria-expanded="menu" aria-haspopup="true" :aria-label="'เมนูของตู้ ' + r.mo"
                                                class="flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 hover:bg-slate-50 hover:text-slate-800 sm:h-8 sm:w-8">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
                                        </button>
                                        <span x-show="menu" x-cloak x-transition.opacity :style="'left:' + (x - 160) + 'px; top:' + (y + 4) + 'px'" class="fixed z-40 block w-40 overflow-hidden rounded-xl border border-slate-200 bg-white p-1 text-left text-sm shadow-xl">
                                            <a :href="r.url" class="block rounded-lg px-3 py-2 font-medium text-slate-700 hover:bg-slate-50">เปิดหน้าตู้</a>
                                            <button type="button" @click="menu = false; $dispatch('open-modal', 'copy-cabinet-' + r.id)" class="block w-full rounded-lg px-3 py-2 text-left font-medium text-slate-700 hover:bg-slate-50">คัดลอกตู้</button>
                                        </span>
                                    </span>
                                    @endeditplan
                                </span>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="! filtered().length">
                        <td colspan="8" class="px-4 py-8 text-center text-sm text-slate-500">{{ $overview['cabinetCount'] ? 'ไม่มีตู้ที่ตรงกับตัวกรอง' : 'ยังไม่มีตู้ไฟฟ้าในโครงการนี้' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-5 py-2.5 text-[13px] text-slate-600" x-show="filtered().length">
            <span x-text="'แสดง ' + ((page - 1) * per + 1) + '–' + Math.min(page * per, filtered().length) + ' จาก ' + filtered().length + ' ตู้'"></span>
            <span class="flex items-center gap-1" x-show="pages() > 1">
                <button type="button" @click="page--" :disabled="page <= 1" class="rounded-md border border-slate-200 px-2 py-1 font-medium hover:bg-slate-50 disabled:opacity-40" aria-label="หน้าก่อน">‹</button>
                <span class="px-1 tabular-nums" x-text="page + ' / ' + pages()"></span>
                <button type="button" @click="page++" :disabled="page >= pages()" class="rounded-md border border-slate-200 px-2 py-1 font-medium hover:bg-slate-50 disabled:opacity-40" aria-label="หน้าถัดไป">›</button>
            </span>
        </div>
    </section>
</div>
