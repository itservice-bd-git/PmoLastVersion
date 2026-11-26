<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\ProjectDelivery;
use App\Services\CommentAttachmentUploader;
use App\Services\DeliveryBrief;
use App\Services\DeliveryChecklist;
use App\Services\DeliveryFlow;
use App\Services\DeliveryWindow;
use App\Support\EventStatuses;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "งานจัดส่ง": the delivery department's own page (Access 'edit.delivery') - the one place it works; the cabinets'
 * Checklist and Planning follow it (DeliveryChecklist). Only the rounds PM sent (not its drafts): what goes, where, the
 * day PM set, the kinds of vehicle and how many people PM asked for - not the rest of the project. Tabs by step; each step
 * goes through DeliveryFlow.
 */
class DeliveryController extends Controller
{
    public const TABS = [
        'tomorrow' => 'ส่งพรุ่งนี้',
        'waiting' => 'รอจัดรถ',
        'today' => 'ส่งวันนี้',
        'scheduled' => 'จัดรถแล้ว',
        'departed' => 'ออกจากโรงงาน',
        'delivered' => 'ส่งถึงแล้ว',
        'rejected' => 'ไม่รับงาน',
    ];

    /** how far back "ส่งถึงแล้ว" lists */
    private const DELIVERED_DAYS = 30;

    public function __construct(private DeliveryFlow $flow) {}

    public function index(Request $request)
    {
        $today = today();
        // Planning's รอบส่ง window (?project=&embed=1): this page without the menu, only that project's rounds - all of them,
        // in round order, no tabs - so the steps are the same buttons as here
        $project = $request->integer('project') ?: null;
        $embed = $request->boolean('embed');
        // two tabs, จัดการรอบส่ง first: everyone lands on the step tabs below; ปฏิทินสัปดาห์ (the dashboard's look) when its
        // tab is pressed (?view=week) - not for a tab, a notice's round or Planning's window
        if (! $project && ! $embed && ! $request->hasAny(['tab', 'round']) && $request->input('view') === 'week') {
            return $this->week($request);
        }
        $all = ProjectDelivery::with('project', 'vehicles.vehicle', 'sender', 'rejecter', 'biller')->whereHas('project')
            ->where('status', '!=', ProjectDelivery::DRAFT)
            ->when($project, fn ($q) => $q->where('project_id', $project),
                fn ($q) => $q->where(fn ($q) => $q->where('status', '!=', ProjectDelivery::DELIVERED)->orWhere('delivered_at', '>=', $today->copy()->subDays(self::DELIVERED_DAYS))))
            ->get();
        $day = fn ($d) => $d->delivery_date->format('Ymd').($d->delivery_time ?: '99');
        // a round waiting for sales is a draft again (ProjectDates) - not here; one billed before the wait goes on as usual
        $live = $all;
        $tomorrow = $today->copy()->addDay();
        // a round not there yet: waiting for a vehicle, scheduled, or on its way
        $toGo = fn ($d) => in_array($d->status, [ProjectDelivery::WAITING, ProjectDelivery::SCHEDULED, ProjectDelivery::DEPARTED], true);
        $tabs = [
            // every round still to go whose days include tomorrow: what the department gets people and vehicles ready for
            'tomorrow' => $live
                ->filter(fn ($d) => $toGo($d) && $d->delivery_date->lte($tomorrow) && $d->lastDay()->gte($tomorrow))
                ->sortBy($day),
            'waiting' => $live->where('status', ProjectDelivery::WAITING)->sortBy($day),
            // its days include today, or it is past them and not there yet
            'today' => $live
                ->filter(fn ($d) => $toGo($d) && $d->delivery_date->lte($today))
                ->sortBy($day),
            'scheduled' => $live->where('status', ProjectDelivery::SCHEDULED)->sortBy($day),
            'departed' => $all->where('status', ProjectDelivery::DEPARTED)->sortBy($day),
            // รอตรวจ (from the driver's link) first, then the latest
            'delivered' => $all->where('status', ProjectDelivery::DELIVERED)->sortBy([fn ($a, $b) => (bool) $b->review_at <=> (bool) $a->review_at, fn ($a, $b) => $b->delivered_at <=> $a->delivered_at]),
            'rejected' => $live->where('status', ProjectDelivery::REJECTED)->sortByDesc('rejected_at'),
        ];
        if ($project) {
            $tabs['all'] = $all->sortBy('round');
        }
        $round = $request->integer('round');
        $tab = $project ? 'all' : $request->input('tab');
        if (! isset($tabs[$tab])) {   // a notice's round opens on its own tab; else the first tab with work in it
            $tab = $round && ($d = $all->firstWhere('id', $round)) ? ($tabs['today']->contains('id', $d->id) ? 'today' : $d->status)
                : collect(['today', 'waiting', 'scheduled'])->first(fn ($k) => $tabs[$k]->isNotEmpty(), 'waiting');
        }
        $vehicles = DeliveryWindow::vehicles($all->flatMap(fn ($d) => $d->vehicles->pluck('vehicle_id'))->filter()->unique()->values()->all());
        $people = $all->flatMap(fn ($d) => $d->people())->merge(array_column($vehicles, 'driver'))->filter()->unique()->sort()->values();
        // what the delivery department loads: each cabinet's MO, its name, type, size and how many
        $names = Cabinet::whereIn('id', $all->flatMap(fn ($d) => $d->cabinet_ids ?? [])->unique())->get(['id', 'mo_no', 'cabinet_name', 'cabinet_type', 'size', 'quantity'])->keyBy('id');

        $files = ProjectDelivery::filesOf($tabs[$tab]->where('status', ProjectDelivery::DELIVERED)->pluck('id'));   // ใบส่งของ / รูป sent with ส่งถึงแล้ว

        return view('deliveries.index', [
            'tabs' => $tabs, 'tabLabels' => self::TABS, 'tab' => $tab, 'round' => $round, 'embed' => $embed, 'project' => $project,
            'rows' => $tabs[$tab]->values()->map(fn ($d) => $this->row($d, $names, $today, $files))->all(),
            'vehicles' => $vehicles,
            'busy' => $this->busy($all),
            'reasons' => ProjectDelivery::rejectReasons(),
            'people' => $people->all(),   // names typed before, offered when typing who goes
        ]);
    }

    /**
     * ปฏิทินสัปดาห์: the week's rounds (sent to the delivery department) as Monday-to-Friday columns, the way the dashboard
     * shows FAT and deliveries - each round on its first day in the week (Saturday and Sunday on Friday's), the state of each
     * step on its card, the rest in a panel; the work itself is done on the step tabs (จัดการรอบส่ง).
     */
    private function week(Request $request)
    {
        $today = today();
        $offset = max(-8, min(8, $request->integer('week')));
        $mon = $today->copy()->startOfWeek(Carbon::MONDAY)->addWeeks($offset);
        $sun = $mon->copy()->addDays(6);
        $rounds = ProjectDelivery::with('project', 'vehicles.vehicle', 'sender', 'rejecter', 'biller')->whereHas('project')
            ->where('status', '!=', ProjectDelivery::DRAFT)
            ->whereDate('delivery_date', '<=', $sun)
            ->where(fn ($q) => $q->whereDate('delivery_date', '>=', $mon)->orWhereDate('delivery_end_date', '>=', $mon))
            ->orderBy('delivery_date')->orderBy('delivery_time')->get();
        $names = Cabinet::whereIn('id', $rounds->flatMap(fn ($d) => $d->cabinet_ids ?? [])->unique())->get(['id', 'mo_no', 'cabinet_name', 'cabinet_type', 'size', 'quantity'])->keyBy('id');

        $short = ['จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.', 'อา.'];
        $rows = $rounds->map(function ($d) use ($names, $today, $mon, $short) {
            $on = $d->firstDay()->copy()->max($mon);
            $row = $this->row($d, $names, $today);

            $done = $d->status === ProjectDelivery::DELIVERED;
            // what has to happen next, in words (the card says this instead of a bar of steps)
            $next = match (true) {
                $done => null,
                $d->status === ProjectDelivery::REJECTED => 'รอ PM เลือกวันใหม่',
                $d->status === ProjectDelivery::WAITING => 'จัดรถ / คน',
                $d->status === ProjectDelivery::DEPARTED => 'บันทึกส่งถึง',
                $row['step']['n'] === 0 => 'ห่อตู้ ('.$row['packedCount'].'/'.count($row['cabinets']).')',
                $row['step']['n'] === 1 => 'แจ้งเปิดบิล',
                default => 'ออกจากโรงงาน',
            };

            return $row + ['col' => min(4, $on->dayOfWeekIso - 1), 'weekend' => $on->dayOfWeekIso > 5 ? $short[$on->dayOfWeekIso - 1].' '.$on->day : null,
                'manage' => route('deliveries.index', ['round' => $d->id]), 'done' => $done, 'next' => $next, 'stepsDone' => min(4, $row['step']['n'])];
        });
        // a day: the rounds still to do first (in the order they go), the delivered ones after, apart
        $days = collect(range(0, 4))->map(function ($i) use ($mon, $short, $today, $rows) {
            $d = $mon->copy()->addDays($i);
            $mine = $rows->where('col', $i);

            return ['date' => $d, 'short' => $short[$i], 'n' => $d->day, 'today' => $d->equalTo($today), 'past' => $d->lt($today),
                'items' => $mine->where('done', false)->values()->all(), 'delivered' => $mine->where('done', true)->values()->all()];
        })->all();
        $fri = $mon->copy()->addDays(4);
        $months = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        $label = ($mon->month === $fri->month ? $mon->day : $mon->day.' '.$months[$mon->month - 1]).'–'.$fri->day.' '.$months[$fri->month - 1].' '.($fri->year + 543);

        return view('deliveries.week', [
            'days' => $days, 'label' => $label, 'offset' => $offset,
            'todayText' => $today->betweenIncluded($mon, $sun) ? ['จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์', 'อาทิตย์'][$today->dayOfWeekIso - 1].' '.$today->day.' '.$months[$today->month - 1] : null,
            // the states the week can be narrowed to, in the order the work goes, with how many rounds are in each
            'states' => collect([ProjectDelivery::WAITING, ProjectDelivery::SCHEDULED, ProjectDelivery::DEPARTED, ProjectDelivery::DELIVERED, ProjectDelivery::REJECTED])
                ->map(fn ($k) => ['key' => $k, 'label' => ProjectDelivery::statusName($k), 'color' => EventStatuses::color('delivery', $k), 'n' => $rows->where('status', $k)->count()])->all(),
        ]);
    }

    /** จัดรถ / คน (or a pickup's รับงาน). */
    public function assign(Request $request, ProjectDelivery $delivery)
    {
        $data = $request->validate([
            'vehicles' => ['nullable', 'array', 'max:20'],
            'vehicles.*.vehicle_id' => ['nullable', 'integer'],
            'vehicles.*.label' => ['nullable', 'string', 'max:150'],
            'vehicles.*.driver_name' => ['nullable', 'string', 'max:100'],
            'vehicles.*.crew' => ['nullable', 'array', 'max:20'],
            'vehicles.*.crew.*' => ['nullable', 'string', 'max:100'],
            'carrier' => ['nullable', 'string', 'max:150'],
            'map_url' => ProjectDelivery::mapRule(),
        ], [], ['map_url' => 'ลิงก์ GPS']);
        $again = $delivery->status === ProjectDelivery::SCHEDULED;
        $this->flow->assign($delivery, $data, $request->user());

        return $this->done($request, $again ? 'บันทึกรถ / คนแล้ว' : ($delivery->method === ProjectDelivery::PICKUP ? 'รับงานแล้ว' : 'จัดรถแล้ว'));
    }

    public function reject(Request $request, ProjectDelivery $delivery)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:100'], 'note' => ['nullable', 'string', 'max:500']], ['reason.required' => 'เลือกเหตุผลที่ไม่รับงาน']);
        $this->flow->reject($delivery, $data['reason'], $data['note'] ?? null, $request->user());

        return $this->done($request, 'ส่งกลับให้ PM แล้ว');
    }

    /** ห่อตู้เสร็จสิ้น: cabinet_ids packed (packed=1) or taken back (packed=0). */
    public function pack(Request $request, ProjectDelivery $delivery)
    {
        $data = $request->validate(['cabinet_ids' => ['required', 'array', 'min:1'], 'cabinet_ids.*' => ['integer'], 'packed' => ['required', 'boolean']]);
        $d = $this->flow->pack($delivery, $data['cabinet_ids'], (bool) $data['packed'], $request->user());
        $message = $data['packed'] ? 'บันทึกห่อตู้แล้ว' : 'เอาการห่อออกแล้ว';

        // the cabinets panel ticks without reloading: it gets back what is packed now, and when
        return $request->wantsJson()
            ? response()->json(['message' => $message, 'packed' => collect($d->packed ?? [])->map(fn ($at) => Carbon::parse($at)->format('d/m H:i')),
                'count' => $d->packedCount(), 'total' => count($d->cabinet_ids ?? [])])
            : back()->with('success', $message);
    }

    public function bill(Request $request, ProjectDelivery $delivery)
    {
        return $this->done($request, 'แจ้งเปิดบิลแล้ว', $this->flow->bill($delivery, $request->user()));
    }

    public function depart(Request $request, ProjectDelivery $delivery)
    {
        return $this->done($request, 'บันทึกออกจากโรงงานแล้ว', $this->flow->depart($delivery, $request->user()));
    }

    public function deliver(Request $request, ProjectDelivery $delivery)
    {
        $data = $request->validate([
            'delivered_at' => ['required', 'date_format:Y-m-d H:i'],
            'invoice_no' => ['nullable', 'string', 'max:50'],
            'issue' => ['nullable', 'string', 'max:500'],
            'cabinet_ids' => ['required', 'array', 'min:1'],
            'cabinet_ids.*' => ['integer'],
            'files' => ['nullable', 'array', 'max:'.ProjectDelivery::FILES_MAX],
            'files.*' => ['file', 'max:20480', 'mimes:'.CommentAttachmentUploader::ALLOWED_MIMES],
        ], ['delivered_at.required' => 'ใส่วันที่และเวลาที่ส่งถึง', 'delivered_at.date_format' => 'วันที่ / เวลาส่งถึงไม่ถูกต้อง', 'invoice_no.max' => 'เลข IV ยาวได้ไม่เกิน 50 ตัวอักษร', 'cabinet_ids.required' => 'ติ๊กตู้ที่ส่งถึงอย่างน้อย 1 ตู้',
            'files.max' => 'แนบได้ครั้งละไม่เกิน '.ProjectDelivery::FILES_MAX.' ไฟล์', 'files.*.max' => 'ไฟล์ :attribute ใหญ่เกิน 20 MB',
            'files.*.mimes' => 'แนบได้เฉพาะ PDF, Word, Excel, รูปภาพ (JPG / PNG), DWG / DXF', 'files.*.uploaded' => 'อัปโหลดไฟล์ไม่สำเร็จ (ไฟล์อาจใหญ่เกินไป)'],
            ['files.*' => 'ที่แนบ']);
        // one field (วันที่ / เวลา) for the day and the time the flow takes apart; the IV number goes with it
        [$data['delivered_date'], $data['delivered_time']] = explode(' ', $data['delivered_at']);
        $data['invoice_no'] ??= null;
        $this->flow->deliver($delivery, $data, $request->user(), $request->file('files', []));

        return $this->done($request, 'บันทึกส่งถึงแล้ว');
    }

    /** ตรวจแล้ว · แจ้ง PM (what the driver sent through the link is in order). */
    public function review(Request $request, ProjectDelivery $delivery)
    {
        return $this->done($request, 'ตรวจแล้ว · แจ้ง PM แล้ว', $this->flow->review($delivery, $request->user()));
    }

    public function back(Request $request, ProjectDelivery $delivery)
    {
        $this->flow->stepBack($delivery, $request->user());

        return $this->done($request, 'ย้อนสถานะแล้ว', $delivery);
    }

    /** One round as the page shows it and its windows fill in. */
    private function row(ProjectDelivery $d, Collection $names, $today, ?Collection $files = null): array
    {
        $left = (int) $today->diffInDays($d->delivery_date, false);   // to its first day
        $end = (int) $today->diffInDays($d->lastDay(), false);

        $unmade = app(DeliveryChecklist::class)->remaining(array_map('intval', $d->cabinet_ids ?? []));   // Checklist not done: not to pack yet

        return [
            'id' => $d->id, 'status' => $d->status, 'state' => $d->stateLabel(), 'color' => $d->stateColor(),
            'project' => $d->project->project_no, 'name' => $d->project->project_name, 'round' => $d->round,
            'cabinets' => collect($d->cabinet_ids ?? [])->map(fn ($id) => ['id' => (int) $id, 'name' => ($c = $names->get($id)) ? ($c->mo_no ?: $c->cabinet_name) : 'ตู้ที่ลบแล้ว',
                'model' => $c && $c->mo_no ? (string) $c->cabinet_name : '', 'type' => (string) $c?->cabinet_type, 'size' => (string) $c?->size, 'qty' => (int) ($c?->quantity ?? 1),
                'packed' => $d->isPacked((int) $id), 'left' => $unmade[(int) $id] ?? 0,
                'at' => isset(($d->packed ?? [])[$id]) ? Carbon::parse($d->packed[$id])->format('d/m H:i') : ''])->values()->all(),
            'packable' => $d->beforeDeparture() && ! $d->billed_at,   // ห่อ / เอาการห่อออก still allowed (the panel's buttons)
            'site' => (string) $d->site, 'map' => (string) $d->map_url, 'nav' => (string) $d->navUrl(), 'contact' => collect([$d->contact_name, $d->contact_phone])->filter()->implode(' '), 'note' => (string) $d->note,
            'method' => $d->method, 'transport' => $d->transport() ?? 'ยังไม่มีรถ', 'carrier' => (string) $d->carrier,
            'vehicles' => $d->vehicles->map(fn ($v) => ['vehicle_id' => $v->vehicle_id ? (string) $v->vehicle_id : '', 'label' => (string) $v->label,
                'name' => $v->name(), 'type' => (string) $v->vehicle?->type, 'driver_name' => (string) $v->driver_name, 'crew' => $v->crew ?? []])->values()->all(),
            // what PM asked for, and what differs from it
            'types' => $d->vehicle_types ?? [], 'crew_size' => (int) $d->crew_size, 'request' => $d->request(), 'other' => $d->otherTypes(),
            'people' => $d->peopleText(), 'short' => $d->shortOfPeople(), 'fewer' => $d->vehiclesShortText(), 'sender' => (string) $d->sender?->name,
            'pending' => (bool) $d->date_pending_at,   // รอ PM ยืนยันวัน (ProjectDates): packing only until the day comes
            'date' => $d->delivery_date->format('Y-m-d'), 'days' => $d->days(), 'when' => $d->when(),
            'left' => $left, 'late' => $d->status !== ProjectDelivery::DELIVERED && $end < 0 && ! $d->date_pending_at, 'during' => $left <= 0 && $end >= 0,
            // the four steps
            'step' => $d->step(), 'packedCount' => $d->packedCount(), 'allPacked' => $d->allPacked(),
            'billed' => $d->billed_at ? $d->billed_at->format('d/m H:i').($d->biller ? ' · '.$d->biller->name : '') : '',
            'departed' => $d->departed_at?->format('d/m H:i'), 'delivered' => $d->delivered_at ? $d->delivered_at->thaiDate().($d->delivered_at->format('H:i') !== '00:00' ? ' '.$d->delivered_at->format('H:i').' น.' : '') : '',
            'delivered_input' => $d->delivered_at?->format('Y-m-d H:i') ?? '', 'invoice_no' => $d->invoice_no,
            'review' => $d->review_at ? $d->review_at->format('d/m H:i') : '',   // รอตรวจ since (the driver's link)
            'receiver' => (string) $d->receiver, 'doc_no' => (string) $d->doc_no, 'issue' => (string) $d->issue,
            'files' => ($files ?? collect())->get($d->id, collect())->map(fn ($f) => ProjectDelivery::fileView($f))->values()->all(),
            'rejected' => $d->status === ProjectDelivery::REJECTED ? trim($d->rejected_reason.($d->rejected_note ? ' · '.$d->rejected_note : '').' · โดย '.$d->rejecter?->name.' '.$d->rejected_at?->format('d/m H:i')) : '',
            // ลิงก์คนขับ (works once it has left: the driver sends the photos and ส่งถึงแล้ว without logging in) and ข้อความส่งงาน
            // (the LINE message for the crew, DeliveryBrief) - from the day the vehicles are on it until it leaves
            'driverUrl' => $briefed = in_array($d->status, [ProjectDelivery::SCHEDULED, ProjectDelivery::DEPARTED], true) && auth()->user()
                ? DriverLinkController::urlFor($d, auth()->user()) : null,
            'brief' => $briefed ? DeliveryBrief::text($d, $names, $briefed) : null,
            'urls' => ['assign' => route('deliveries.assign', $d), 'reject' => route('deliveries.reject', $d), 'pack' => route('deliveries.pack', $d), 'bill' => route('deliveries.bill', $d),
                'review' => route('deliveries.review', $d), 'depart' => route('deliveries.depart', $d), 'deliver' => route('deliveries.deliver', $d), 'back' => route('deliveries.back', $d)],
        ];
    }

    /** vehicle id => day => what it already carries that day ("SO-690104 รอบ 1"), for the warning when putting it on a round. */
    private function busy(Collection $all): array
    {
        $out = [];
        foreach ($all->filter(fn ($d) => in_array($d->status, [ProjectDelivery::SCHEDULED, ProjectDelivery::DEPARTED], true)) as $d) {
            foreach ($d->vehicles->pluck('vehicle_id')->filter() as $vid) {
                foreach ($d->days() as $day) {
                    $out[$vid][$day][] = ['id' => $d->id, 'label' => $d->project->project_no.' รอบ '.$d->round];
                }
            }
        }

        return $out;
    }

    /** Saved: back to the page, or as JSON for the panels - with the round as it is now ($d) when the panel stays on it. */
    private function done(Request $request, string $message, ?ProjectDelivery $d = null)
    {
        if (! $request->wantsJson()) {
            return back()->with('success', $message);
        }
        $d = $d?->fresh(['project', 'vehicles.vehicle', 'sender', 'rejecter', 'biller']);
        $names = $d ? Cabinet::whereIn('id', $d->cabinet_ids ?? [])->get(['id', 'mo_no', 'cabinet_name', 'cabinet_type', 'size', 'quantity'])->keyBy('id') : collect();

        return response()->json(['message' => $message] + ($d ? ['row' => $this->row($d, $names, today(), ProjectDelivery::filesOf([$d->id]))] : []));
    }
}
