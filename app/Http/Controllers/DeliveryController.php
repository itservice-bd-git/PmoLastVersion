<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\ProjectDelivery;
use App\Services\DeliveryFlow;
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
    public const TABS = ['waiting' => 'รอจัดรถ', 'today' => 'วันนี้', 'scheduled' => 'จัดรถแล้ว', 'departed' => 'ออกจากโรงงาน', 'delivered' => 'ส่งถึงแล้ว', 'rejected' => 'ไม่รับงาน'];

    /** how far back "ส่งถึงแล้ว" lists */
    private const DELIVERED_DAYS = 30;

    public function __construct(private DeliveryFlow $flow) {}

    public function index(Request $request)
    {
        $today = today();
        $all = ProjectDelivery::with('project', 'vehicles.vehicle', 'sender', 'rejecter', 'biller')->whereHas('project')
            ->where('status', '!=', ProjectDelivery::DRAFT)
            ->where(fn ($q) => $q->where('status', '!=', ProjectDelivery::DELIVERED)->orWhere('delivered_at', '>=', $today->copy()->subDays(self::DELIVERED_DAYS)))
            ->get();
        $day = fn ($d) => $d->delivery_date->format('Ymd').($d->delivery_time ?: '99');
        $tabs = [
            'waiting' => $all->where('status', ProjectDelivery::WAITING)->sortBy($day),
            // its days include today, or it is past them and not there yet
            'today' => $all->filter(fn ($d) => in_array($d->status, [ProjectDelivery::WAITING, ProjectDelivery::SCHEDULED, ProjectDelivery::DEPARTED], true) && $d->delivery_date->lte($today))->sortBy($day),
            'scheduled' => $all->where('status', ProjectDelivery::SCHEDULED)->sortBy($day),
            'departed' => $all->where('status', ProjectDelivery::DEPARTED)->sortBy($day),
            'delivered' => $all->where('status', ProjectDelivery::DELIVERED)->sortByDesc('delivered_at'),
            'rejected' => $all->where('status', ProjectDelivery::REJECTED)->sortByDesc('rejected_at'),
        ];
        $round = $request->integer('round');
        $tab = $request->input('tab');
        if (! isset($tabs[$tab])) {   // a notice's round opens on its own tab; else the first tab with work in it
            $tab = $round && ($d = $all->firstWhere('id', $round)) ? ($tabs['today']->contains('id', $d->id) ? 'today' : $d->status)
                : collect(['today', 'waiting', 'scheduled'])->first(fn ($k) => $tabs[$k]->isNotEmpty(), 'waiting');
        }
        $vehicles = ProjectDeliveryController::vehicles($all->flatMap(fn ($d) => $d->vehicles->pluck('vehicle_id'))->filter()->unique()->values()->all());
        $people = $all->flatMap(fn ($d) => $d->people())->merge(array_column($vehicles, 'driver'))->filter()->unique()->sort()->values();
        // what the delivery department loads: each cabinet's MO, its name, type, size and how many
        $names = Cabinet::whereIn('id', $all->flatMap(fn ($d) => $d->cabinet_ids ?? [])->unique())->get(['id', 'mo_no', 'cabinet_name', 'cabinet_type', 'size', 'quantity'])->keyBy('id');

        return view('deliveries.index', [
            'tabs' => $tabs, 'tab' => $tab, 'round' => $round,
            'rows' => $tabs[$tab]->values()->map(fn ($d) => $this->row($d, $names, $today))->all(),
            'vehicles' => $vehicles,
            'busy' => $this->busy($all),
            'reasons' => ProjectDelivery::rejectReasons(),
            'people' => $people->all(),   // names typed before, offered when typing who goes
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
        ]);
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
        $this->flow->bill($delivery, $request->user());

        return $this->done($request, 'แจ้งเปิดบิลแล้ว');
    }

    public function depart(Request $request, ProjectDelivery $delivery)
    {
        $this->flow->depart($delivery, $request->user());

        return $this->done($request, 'บันทึกออกจากโรงงานแล้ว');
    }

    public function deliver(Request $request, ProjectDelivery $delivery)
    {
        $data = $request->validate([
            'delivered_date' => ['required', 'date'],
            'delivered_time' => ['nullable', 'date_format:H:i'],
            'receiver' => ['required', 'string', 'max:150'],
            'doc_no' => ['nullable', 'string', 'max:50'],
            'issue' => ['nullable', 'string', 'max:500'],
            'cabinet_ids' => ['required', 'array', 'min:1'],
            'cabinet_ids.*' => ['integer'],
        ], ['delivered_date.required' => 'ใส่วันที่ส่งถึง', 'receiver.required' => 'ใส่ชื่อผู้รับ', 'cabinet_ids.required' => 'ติ๊กตู้ที่ส่งถึงอย่างน้อย 1 ตู้']);
        $this->flow->deliver($delivery, $data, $request->user());

        return $this->done($request, 'บันทึกส่งถึงแล้ว');
    }

    public function back(Request $request, ProjectDelivery $delivery)
    {
        $this->flow->stepBack($delivery, $request->user());

        return $this->done($request, 'ย้อนสถานะแล้ว');
    }

    /** One round as the page shows it and its windows fill in. */
    private function row(ProjectDelivery $d, Collection $names, $today): array
    {
        $left = (int) $today->diffInDays($d->delivery_date, false);   // to its first day
        $end = (int) $today->diffInDays($d->lastDay(), false);

        return [
            'id' => $d->id, 'status' => $d->status, 'state' => $d->stateLabel(), 'color' => $d->stateColor(),
            'project' => $d->project->project_no, 'name' => $d->project->project_name, 'round' => $d->round,
            'cabinets' => collect($d->cabinet_ids ?? [])->map(fn ($id) => ['id' => (int) $id, 'name' => ($c = $names->get($id)) ? ($c->mo_no ?: $c->cabinet_name) : 'ตู้ที่ลบแล้ว',
                'model' => $c && $c->mo_no ? (string) $c->cabinet_name : '', 'type' => (string) $c?->cabinet_type, 'size' => (string) $c?->size, 'qty' => (int) ($c?->quantity ?? 1),
                'packed' => $d->isPacked((int) $id),
                'at' => isset(($d->packed ?? [])[$id]) ? Carbon::parse($d->packed[$id])->format('d/m H:i') : ''])->values()->all(),
            'packable' => $d->beforeDeparture() && ! $d->billed_at,   // ห่อ / เอาการห่อออก still allowed (the panel's buttons)
            'site' => (string) $d->site, 'contact' => collect([$d->contact_name, $d->contact_phone])->filter()->implode(' '), 'note' => (string) $d->note,
            'method' => $d->method, 'transport' => $d->transport() ?? 'ยังไม่มีรถ', 'carrier' => (string) $d->carrier,
            'vehicles' => $d->vehicles->map(fn ($v) => ['vehicle_id' => $v->vehicle_id ? (string) $v->vehicle_id : '', 'label' => (string) $v->label,
                'name' => $v->name(), 'type' => (string) $v->vehicle?->type, 'driver_name' => (string) $v->driver_name, 'crew' => $v->crew ?? []])->values()->all(),
            // what PM asked for, and what differs from it
            'types' => $d->vehicle_types ?? [], 'crew_size' => (int) $d->crew_size, 'request' => $d->request(), 'other' => $d->otherTypes(),
            'people' => $d->peopleText(), 'short' => $d->shortOfPeople(), 'sender' => (string) $d->sender?->name,
            'date' => $d->delivery_date->format('Y-m-d'), 'days' => $d->days(), 'when' => $d->when(),
            'left' => $left, 'late' => $d->status !== ProjectDelivery::DELIVERED && $end < 0, 'during' => $left <= 0 && $end >= 0,
            // the four steps
            'step' => $d->step(), 'packedCount' => $d->packedCount(), 'allPacked' => $d->allPacked(),
            'billed' => $d->billed_at ? $d->billed_at->format('d/m H:i').($d->biller ? ' · '.$d->biller->name : '') : '',
            'departed' => $d->departed_at?->format('d/m H:i'), 'delivered' => $d->delivered_at ? $d->delivered_at->thaiDate().($d->delivered_at->format('H:i') !== '00:00' ? ' '.$d->delivered_at->format('H:i').' น.' : '') : '',
            'delivered_date' => $d->delivered_at?->format('Y-m-d') ?? '', 'delivered_time' => $d->delivered_at && $d->delivered_at->format('H:i') !== '00:00' ? $d->delivered_at->format('H:i') : '',
            'receiver' => (string) $d->receiver, 'doc_no' => (string) $d->doc_no, 'issue' => (string) $d->issue,
            'rejected' => $d->status === ProjectDelivery::REJECTED ? trim($d->rejected_reason.($d->rejected_note ? ' · '.$d->rejected_note : '').' · โดย '.$d->rejecter?->name.' '.$d->rejected_at?->format('d/m H:i')) : '',
            'urls' => ['assign' => route('deliveries.assign', $d), 'reject' => route('deliveries.reject', $d), 'pack' => route('deliveries.pack', $d), 'bill' => route('deliveries.bill', $d),
                'depart' => route('deliveries.depart', $d), 'deliver' => route('deliveries.deliver', $d), 'back' => route('deliveries.back', $d)],
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

    private function done(Request $request, string $message)
    {
        return $request->wantsJson() ? response()->json(['message' => $message]) : back()->with('success', $message);
    }
}
