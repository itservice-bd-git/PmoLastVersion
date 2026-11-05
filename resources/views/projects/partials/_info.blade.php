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
            ['วันครบกำหนด', $project->due_date ? $project->due_date->thaiDate().($daysLeft !== null
                ? ' <span class="ml-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold '.($daysLeft < 0 ? 'bg-red-100 text-red-700' : ($daysLeft <= 7 ? 'bg-orange-100 text-orange-700' : 'bg-slate-100 text-slate-600')).'">'
                    .($daysLeft < 0 ? 'เลยกำหนด '.abs($daysLeft).' วัน' : ($daysLeft === 0 ? 'ครบกำหนดวันนี้' : 'เหลือ '.$daysLeft.' วัน')).'</span>' : '').$dateTools : null, 'tabular-nums'],
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
    // what the folded card still shows: the facts people look up most
    $keyFacts = collect($groups)->flatten(1)->keyBy(0)->only(['ลูกค้า', 'วันครบกำหนด', 'ผู้จัดการโครงการ (PM)', 'เลขที่ใบสั่งซื้อ (PO)'])->values()->all();

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
        // ข้อมูลโครงการ, folded: the facts people look up most, as label / value rows (the same values as the full list below)
        $byLabel = collect($groups)->flatten(1)->keyBy(0);
        $sizes = $project->cabinets()->whereNotNull('size')->where('size', '!=', '')->pluck('size')->countBy()->sortDesc();
        $facts = array_values(array_filter([
            $byLabel['ลูกค้า'] ?? null,
            $byLabel['เลขที่ใบสั่งซื้อ (PO)'] ?? null,
            $byLabel['ใบสั่งขาย / อ้างอิง'] ?? null,
            $byLabel['ประเภทงาน'] ?? null,
            ['ขนาดตู้', $sizes->isNotEmpty() ? e($sizes->keys()->first()).($sizes->count() > 1 ? ' <span class="font-normal text-slate-500">และอีก '.($sizes->count() - 1).' ขนาด</span>' : '') : null, 'tabular-nums'],
            ['จำนวนตู้', $s['total'].' ตู้', ''],
            $byLabel['วันเริ่ม'] ?? null,
            $byLabel['วันครบกำหนด'] ?? null,
            $byLabel['ผู้จัดการโครงการ (PM)'] ?? null,
        ]));

        // สถานะโครงการ: เริ่ม → ผลิต (the bar) → FAT → ส่งมอบ → ปิดโครงการ, from what the page already has
        $th = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        $short = fn ($d) => $d ? $d->day.' '.$th[$d->month - 1].($d->year !== today()->year ? ' '.substr((string) ($d->year + 543), 2) : '') : '';
        // "17–18 พ.ย.", "30 พ.ย. – 2 ธ.ค.", one day: "17 พ.ย."
        $range = fn ($a, $b) => ! $b || $b->lte($a) ? $short($a) : ($a->format('Y-m') === $b->format('Y-m') ? $a->day.'–'.$short($b) : $short($a).' – '.$short($b));
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
        // the first one still to come is "ถัดไป"
        foreach ([&$fatNode, &$dlNode] as &$n) {
            if (in_array($n[0], ['wait', 'part'], true)) { $n[] = true; break; }
        }
        unset($n);
        $closed = $project->status === 'completed';
        $dot = function (string $state, ?string $text = null, bool $next = false) {
            $check = '<svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12l5 5L20 7"/></svg>';
            return match ($state) {
                'done' => '<span class="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-600 text-white">'.$check.'</span>',
                'late', 'fail' => '<span class="flex h-7 w-7 items-center justify-center rounded-full border-[3px] border-red-600 bg-white text-[11px] font-bold text-red-700">'.e($text ?? '!').'</span>',
                'todo' => '<span class="block h-6 w-6 rounded-full border-2 border-dashed border-slate-400 bg-white"></span>',
                default => $next ? '<span class="flex h-7 w-7 items-center justify-center rounded-full border-[3px] border-blue-600 bg-white text-[11px] font-bold text-blue-700">'.($text ? e($text) : '<span class="block h-2.5 w-2.5 rounded-full bg-blue-600"></span>').'</span>'
                    : '<span class="flex h-6 w-6 items-center justify-center rounded-full border-2 border-slate-300 bg-white text-[10px] font-bold text-slate-600">'.e($text ?? '').'</span>',
            };
        };
        $tone = fn (array $n) => match ($n[0]) { 'done' => 'text-emerald-700', 'late', 'fail' => 'text-red-700', 'todo' => 'text-slate-500', default => ! empty($n[4]) ? 'text-blue-700' : 'text-slate-800' };
        $fatText = $fatNode[0] === 'fail' ? (string) ($tlFat->round + 1) : null;
        $dlText = $dlNode[0] === 'part' ? $sent->count().'/'.$tlRounds->count() : null;
        $go = fn (array $n, string $event) => $n[3] ? '@click="$dispatch(\''.$event.'\', \''.$n[3].'\')"' : '';
    @endphp
    <section class="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
        {{-- folded by default (the facts above); the choice is remembered on this browser --}}
        <div class="flex min-w-0 flex-col rounded-[14px] border border-slate-200 bg-white px-5 pb-3.5 pt-4" aria-label="ข้อมูลโครงการ"
             x-data="{ more: (() => { try { return localStorage.getItem('pmo.projectDetails') === 'open'; } catch (e) { return false; } })() }"
             x-init="$watch('more', (v) => { try { localStorage.setItem('pmo.projectDetails', v ? 'open' : 'closed'); } catch (e) {} })">
            <h3 class="text-[15px] font-bold text-ae-navy">ข้อมูลโครงการ</h3>
            <dl x-show="! more" class="mt-1 grid grid-cols-[8.5rem_minmax(0,1fr)] text-sm">
                @foreach ($facts as [$label, $value, $class])
                    <dt class="border-b border-slate-100 py-2.5 text-slate-600 last-of-type:border-0">{{ $label === 'ผู้จัดการโครงการ (PM)' ? 'PM' : ($label === 'เลขที่ใบสั่งซื้อ (PO)' ? 'เลขที่ PO' : $label) }}</dt>
                    <dd class="min-w-0 break-words border-b border-slate-100 py-2.5 {{ $value === null ? 'text-slate-400' : 'font-semibold text-slate-900 '.$class }} {{ $loop->last ? 'border-0' : '' }}">{!! $value ?? 'ยังไม่ระบุ' !!}</dd>
                @endforeach
            </dl>
            <div id="project-details" x-show="more" x-cloak class="mt-3 grid gap-x-8 gap-y-5 2xl:grid-cols-2">
                @foreach ($groups as $title => $fields)
                    @continue(! $fields)
                    <div>
                        <h4 class="mb-1.5 text-sm font-bold text-ae-navy">{{ $title }}</h4>
                        <dl class="grid grid-cols-[8.5rem_minmax(0,1fr)] text-sm">
                            @foreach ($fields as [$label, $value, $class])
                                <dt class="border-b border-slate-100 py-2 text-slate-600">{{ $label }}</dt>
                                @if ($class === 'badge')
                                    <dd class="flex flex-wrap items-center gap-2 border-b border-slate-100 py-2"><x-project-status :status="$project->status" /><x-priority-flag :priority="$project->priority" :always="true" /></dd>
                                @else
                                    <dd class="min-w-0 break-words border-b border-slate-100 py-2 {{ $value === null ? 'text-slate-400' : 'font-semibold text-slate-900 '.$class }}">{!! $value ?? 'ยังไม่ระบุ' !!}</dd>
                                @endif
                            @endforeach
                        </dl>
                    </div>
                @endforeach
            </div>
            <button type="button" @click="more = ! more" :aria-expanded="more" aria-controls="project-details"
                    class="mt-auto inline-flex items-center gap-1 self-start pt-2 text-[13px] font-semibold text-blue-700 hover:underline">
                <span x-text="more ? 'ย่อ' : 'ดูรายละเอียดเพิ่มเติม'">ดูรายละเอียดเพิ่มเติม</span>
                <svg class="h-3.5 w-3.5 transition-transform" :class="more && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2.6" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
            </button>
        </div>

        <div class="flex min-w-0 flex-col gap-5 rounded-[14px] border border-slate-200 bg-white px-5 pb-5 pt-4 sm:px-6" aria-label="ภาพรวมการผลิต">
            <div class="flex flex-wrap items-end gap-x-6 gap-y-3">
                <div>
                    <h3 class="text-[15px] font-bold text-ae-navy">ความคืบหน้าการผลิต</h3>
                    <p class="flex flex-wrap items-baseline gap-x-2">
                        <span class="text-[40px] font-bold leading-tight tracking-tight tabular-nums text-ae-navy">{{ $s['progress'] }}%</span>
                        @if ($pace)<span class="text-[13px] font-semibold {{ $barText }}">{{ $pace[0] }}{{ $s['timeElapsed'] !== null ? ' · เวลาผ่านไป '.$s['timeElapsed'].'%' : '' }}</span>@endif
                    </p>
                </div>
                <dl class="flex flex-wrap gap-x-6 gap-y-2 text-[13px] sm:ml-auto sm:pb-1">
                    @foreach ([['เสร็จแล้ว', $s['completed'], 'bg-emerald-600'], ['กำลังดำเนินการ', $s['inProgress'], 'bg-blue-600'], ['พักไว้', $s['onHold'], 'bg-amber-400'], ['ยังไม่เริ่ม', $s['notStarted'], 'bg-slate-300']] as [$label, $n, $color])
                        @continue($label === 'พักไว้' && ! $n)
                        <div>
                            <dt class="flex items-center gap-1.5 text-slate-600"><span class="h-2 w-2 rounded-full {{ $color }}"></span>{{ $label }}</dt>
                            <dd class="mt-0.5 text-xl font-bold tabular-nums {{ $n ? 'text-slate-900' : 'text-slate-400' }}">{{ $n }} <span class="text-[13px] font-medium text-slate-500">ตู้</span></dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <div>
                <h4 class="mb-2.5 text-[13px] font-bold text-slate-600">สถานะโครงการ</h4>
                {{-- md and up: one line; the ผลิต stretch is the production bar (the tick = where time says it should be) --}}
                <div class="hidden md:block" role="img"
                     aria-label="เริ่มโครงการ {{ $short($project->start_date) ?: 'ยังไม่ระบุ' }} · ผลิต {{ $s['progress'] }}% · {{ $fatNode[1] }} {{ $fatNode[2] }} · {{ $dlNode[1] }} {{ $dlNode[2] }} · {{ $closed ? 'ปิดโครงการแล้ว' : 'ครบกำหนด '.$short($project->due_date) }}">
                    <div class="grid grid-cols-[28px_minmax(0,2fr)_28px_minmax(0,1fr)_28px_minmax(0,1fr)_28px] items-center">
                        <span class="flex justify-center">{!! $dot($project->start_date && $project->start_date->lte(today()) ? 'done' : 'wait') !!}</span>
                        <span class="relative mx-1 h-1.5 rounded-full bg-slate-200">
                            <span class="absolute inset-y-0 left-0 rounded-full {{ $barColor }}" style="width: {{ $s['progress'] }}%"></span>
                            @if ($s['timeElapsed'] !== null && $s['progress'] < 100)
                                <span class="absolute -top-2 h-[22px] w-0.5 -translate-x-1/2 rounded bg-slate-900" style="left: {{ $s['timeElapsed'] }}%" title="เวลาผ่านไป {{ $s['timeElapsed'] }}%"></span>
                            @endif
                        </span>
                        <span class="flex justify-center">{!! $dot($fatNode[0], $fatText, ! empty($fatNode[4])) !!}</span>
                        <span class="mx-1 h-0.5 {{ $fatNode[0] === 'done' ? 'bg-emerald-600' : 'bg-slate-200' }}"></span>
                        <span class="flex justify-center">{!! $dot($dlNode[0], $dlText, ! empty($dlNode[4])) !!}</span>
                        <span class="mx-1 h-0.5 {{ $dlNode[0] === 'done' ? 'bg-emerald-600' : 'bg-slate-200' }}"></span>
                        <span class="flex justify-center">{!! $dot($closed ? 'done' : 'wait') !!}</span>
                    </div>
                    <div class="mt-2.5 grid grid-cols-[28px_minmax(0,2fr)_28px_minmax(0,1fr)_28px_minmax(0,1fr)_28px] text-[13px] leading-snug">
                        <span class="justify-self-start whitespace-nowrap"><b class="block font-semibold text-slate-900">เริ่มโครงการ</b><span class="text-slate-600">{{ $short($project->start_date) ?: 'ยังไม่ระบุ' }}</span></span>
                        <span class="justify-self-center whitespace-nowrap text-center"><b class="block font-semibold {{ $barText }}">ผลิต {{ $s['progress'] }}%</b>
                            <span class="text-slate-600">{{ $s['timeElapsed'] !== null && $s['progress'] < 100 ? 'ควรได้ '.$s['timeElapsed'].'% ตามเวลา' : ($s['progress'] >= 100 ? 'ผลิตครบ' : $s['total'].' ตู้') }}</span></span>
                        <button type="button" {!! $go($fatNode, 'pmo-fat') !!} @disabled(! $fatNode[3]) class="justify-self-center whitespace-nowrap text-center disabled:cursor-default">
                            <b class="block font-semibold {{ $tone($fatNode) }}">{{ $fatNode[1] }}{{ ! empty($fatNode[4]) ? ' · ถัดไป' : '' }}</b><span class="{{ $fatNode[3] && $fatNode[0] === 'todo' ? 'font-semibold text-blue-700' : 'text-slate-600' }} tabular-nums">{{ $fatNode[2] }}</span></button>
                        <span></span>
                        <button type="button" {!! $go($dlNode, 'pmo-delivery') !!} @disabled(! $dlNode[3]) class="justify-self-center whitespace-nowrap text-center disabled:cursor-default">
                            <b class="block font-semibold {{ $tone($dlNode) }}">{{ $dlNode[1] }}{{ ! empty($dlNode[4]) ? ' · ถัดไป' : '' }}</b><span class="{{ $dlNode[3] ? 'text-blue-700' : 'text-slate-600' }} tabular-nums">{{ $dlNode[2] }}</span></button>
                        <span></span>
                        <span class="justify-self-end whitespace-nowrap text-right"><b class="block font-semibold {{ $closed ? 'text-emerald-700' : 'text-slate-900' }}">ปิดโครงการ</b><span class="text-slate-600">{{ $closed ? 'ปิดแล้ว' : ($project->due_date ? 'ครบกำหนด '.$short($project->due_date) : 'ยังไม่มีวันครบกำหนด') }}</span></span>
                    </div>
                </div>
                {{-- a phone: the same steps down the page --}}
                <ol class="space-y-0 md:hidden">
                    <li class="flex gap-3"><span class="flex flex-col items-center">{!! $dot($project->start_date && $project->start_date->lte(today()) ? 'done' : 'wait') !!}<span class="w-0.5 flex-1 bg-slate-200"></span></span>
                        <span class="pb-3 text-[13px]"><b class="block">เริ่มโครงการ</b><span class="text-slate-600">{{ $short($project->start_date) ?: 'ยังไม่ระบุ' }}</span></span></li>
                    <li class="flex gap-3"><span class="flex w-7 flex-col items-center"><span class="w-0.5 flex-1 bg-slate-200"></span></span>
                        <span class="flex-1 pb-3 text-[13px]"><b class="block {{ $barText }}">ผลิต {{ $s['progress'] }}%</b>
                            <span class="relative mt-1 block h-1.5 rounded-full bg-slate-200"><span class="absolute inset-y-0 left-0 rounded-full {{ $barColor }}" style="width: {{ $s['progress'] }}%"></span></span></span></li>
                    <li class="flex gap-3"><span class="flex flex-col items-center">{!! $dot($fatNode[0], $fatText, ! empty($fatNode[4])) !!}<span class="w-0.5 flex-1 bg-slate-200"></span></span>
                        <button type="button" {!! $go($fatNode, 'pmo-fat') !!} @disabled(! $fatNode[3]) class="pb-3 text-left text-[13px]"><b class="block {{ $tone($fatNode) }}">{{ $fatNode[1] }}</b><span class="text-slate-600">{{ $fatNode[2] }}</span></button></li>
                    <li class="flex gap-3"><span class="flex flex-col items-center">{!! $dot($dlNode[0], $dlText, ! empty($dlNode[4])) !!}<span class="w-0.5 flex-1 bg-slate-200"></span></span>
                        <button type="button" {!! $go($dlNode, 'pmo-delivery') !!} @disabled(! $dlNode[3]) class="pb-3 text-left text-[13px]"><b class="block {{ $tone($dlNode) }}">{{ $dlNode[1] }}</b><span class="text-blue-700">{{ $dlNode[2] }}</span></button></li>
                    <li class="flex gap-3">{!! $dot($closed ? 'done' : 'wait') !!}
                        <span class="text-[13px]"><b class="block">ปิดโครงการ</b><span class="text-slate-600">{{ $closed ? 'ปิดแล้ว' : 'ครบกำหนด '.$short($project->due_date) }}</span></span></li>
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
            <span class="text-xs text-slate-400">ไม่มี: {{ $quiet->implode(' · ') }}</span>
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
                        <th class="hidden px-2 py-2 sm:table-cell">ส่ง</th>
                        <th class="hidden whitespace-nowrap px-2 py-2 text-right sm:table-cell">งานเลยกำหนด</th>
                        <th class="px-2 py-2"><span class="sr-only">ดำเนินการ</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="r in paged()" :key="r.id">
                        <tr class="cursor-pointer hover:bg-slate-50 hover:shadow-[inset_3px_0_0_#2563eb]" @click="if (! $event.target.closest('a, button')) window.location = r.url">
                            <td class="py-3 pl-5 pr-0 text-right align-top text-sm font-semibold tabular-nums text-slate-400" x-text="r.no"></td>
                            <td class="max-w-[18rem] px-4 py-3" :title="r.mo">
                                <span class="flex items-center gap-2">
                                    <a :href="r.url" class="line-clamp-2 break-words font-semibold leading-snug text-ae-navy hover:underline" x-text="r.site"></a>
                                    <span x-show="r.status === 'on_hold'" class="shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700">พักไว้</span>
                                </span>
                                <div class="truncate text-[13px] text-slate-600" x-text="r.model"></div>
                            </td>
                            <td class="px-2 py-3">
                                <div class="flex min-w-[7rem] items-center gap-2">
                                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-1.5 rounded-full bg-emerald-600" :style="'width:' + r.progress + '%'"></div></div>
                                    <span class="w-9 text-right text-[13px] font-bold tabular-nums" :class="r.progress ? 'text-slate-800' : 'text-slate-400'" x-text="r.progress + '%'"></span>
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
                            <td class="hidden whitespace-nowrap px-2 py-3 sm:table-cell">
                                <span class="tabular-nums text-slate-600" x-text="r.due || '-'"></span>
                                <span x-show="r.daysLeft !== null" class="text-xs font-semibold" :class="dueClass(r.daysLeft)" x-text="dueLabel(r.daysLeft)"></span>
                            </td>
                            <td class="hidden whitespace-nowrap px-2 py-3 text-right sm:table-cell">
                                <span x-show="r.lateTasks" class="rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-bold text-red-700" x-text="r.lateTasks + ' งาน'"></span>
                                <span x-show="! r.lateTasks" class="text-xs text-slate-400">ไม่มี</span>
                            </td>
                            <td class="whitespace-nowrap py-3 pl-2 pr-5 text-right">
                                <span class="inline-flex items-center gap-1.5">
                                    <a :href="r.url" class="hidden rounded-lg px-2.5 py-1 text-[13px] font-semibold text-blue-700 hover:bg-blue-50 sm:inline-block">ดูรายละเอียด</a>
                                    @editplan
                                    {{-- the cabinet's other actions (its copy window: copy-cabinet-{id}) --}}
                                    {{-- fixed, from the button's place: the table's scroll box would clip it otherwise --}}
                                    <span x-data="{ menu: false, x: 0, y: 0 }" @click.outside="menu = false" @keydown.escape="menu = false" @scroll.window="menu = false" @resize.window="menu = false">
                                        <button type="button" @click="const b = $el.getBoundingClientRect(); x = b.right; y = b.bottom; menu = ! menu" :aria-expanded="menu" aria-haspopup="true" :aria-label="'เมนูของตู้ ' + r.mo"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 hover:bg-slate-50 hover:text-slate-800">
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
                        <td colspan="7" class="px-4 py-8 text-center text-sm text-slate-400">{{ $overview['cabinetCount'] ? 'ไม่มีตู้ที่ตรงกับตัวกรอง' : 'ยังไม่มีตู้ไฟฟ้าในโครงการนี้' }}</td>
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
