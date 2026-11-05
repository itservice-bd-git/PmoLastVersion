<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\ProjectDelivery;
use App\Services\DeliveryFlow;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * "งานจัดส่ง": the delivery department's own page (Access 'edit.delivery'). Only the rounds PM sent (not its drafts) -
 * what goes, where, the kind of vehicle and how many people PM asked for, the window PM gave - not the rest of the
 * project. Tabs by step; each step goes through DeliveryFlow.
 */
class DeliveryController extends Controller
{
    public const TABS = ['waiting' => 'รอกำหนดวัน', 'today' => 'วันนี้', 'scheduled' => 'กำหนดวันแล้ว', 'departed' => 'ออกจากโรงงาน', 'delivered' => 'ส่งถึงแล้ว', 'rejected' => 'ไม่รับงาน'];

    /** how far back "ส่งถึงแล้ว" lists */
    private const DELIVERED_DAYS = 30;

    public function __construct(private DeliveryFlow $flow) {}

    public function index(Request $request)
    {
        $today = today();
        $all = ProjectDelivery::with('project', 'vehicle', 'sender', 'rejecter')->whereHas('project')
            ->where('status', '!=', ProjectDelivery::DRAFT)
            ->where(fn ($q) => $q->where('status', '!=', ProjectDelivery::DELIVERED)->orWhere('delivered_at', '>=', $today->copy()->subDays(self::DELIVERED_DAYS)))
            ->get();
        $on = fn (ProjectDelivery $d) => in_array($d->status, [ProjectDelivery::SCHEDULED, ProjectDelivery::DEPARTED], true);
        $tabs = [
            'waiting' => $all->where('status', ProjectDelivery::WAITING)->sortBy(fn ($d) => $d->window_to->format('Ymd').$d->window_from->format('Ymd')),
            // its days include today, or it is past them and not there yet
            'today' => $all->filter(fn ($d) => $on($d) && $d->delivery_date->lte($today))->sortBy(fn ($d) => $d->delivery_date->format('Ymd').($d->delivery_time ?: '99')),
            'scheduled' => $all->where('status', ProjectDelivery::SCHEDULED)->sortBy(fn ($d) => $d->delivery_date->format('Ymd').($d->delivery_time ?: '99')),
            'departed' => $all->where('status', ProjectDelivery::DEPARTED)->sortBy(fn ($d) => $d->delivery_date->format('Ymd')),
            'delivered' => $all->where('status', ProjectDelivery::DELIVERED)->sortByDesc('delivered_at'),
            'rejected' => $all->where('status', ProjectDelivery::REJECTED)->sortByDesc('rejected_at'),
        ];
        $round = $request->integer('round');
        $tab = $request->input('tab');
        if (! isset($tabs[$tab])) {   // a notice's round opens on its own tab; else the first tab with work in it
            $tab = $round && ($d = $all->firstWhere('id', $round)) ? ($d->status === ProjectDelivery::WAITING ? 'waiting' : ($d->status === ProjectDelivery::REJECTED ? 'rejected'
                : ($d->status === ProjectDelivery::DELIVERED ? 'delivered' : ($on($d) && $d->delivery_date->lte($today) ? 'today' : $d->status))))
                : collect(['waiting', 'today', 'scheduled'])->first(fn ($k) => $tabs[$k]->isNotEmpty(), 'waiting');
        }
        $vehicles = ProjectDeliveryController::vehicles($all->pluck('vehicle_id')->filter()->unique()->all());
        $people = $all->flatMap(fn ($d) => $d->people())->merge(array_column($vehicles, 'driver'))->filter()->unique()->sort()->values();
        $names = Cabinet::whereIn('id', $all->flatMap(fn ($d) => $d->cabinet_ids ?? [])->unique())->get(['id', 'mo_no', 'cabinet_name'])
            ->mapWithKeys(fn ($c) => [$c->id => $c->mo_no ?: $c->cabinet_name]);

        return view('deliveries.index', [
            'tabs' => $tabs, 'tab' => $tab, 'round' => $round,
            'rows' => $tabs[$tab]->values()->map(fn ($d) => $this->row($d, $names, $today))->all(),
            'vehicles' => $vehicles,
            'busy' => $this->busy($all),
            'reasons' => ProjectDelivery::rejectReasons(),
            'people' => $people->all(),   // names typed before, offered when typing who goes
        ]);
    }

    public function schedule(Request $request, ProjectDelivery $delivery)
    {
        $data = $request->validate([
            'delivery_date' => ['required', 'date'],
            'delivery_time' => ['nullable', 'date_format:H:i'],
            'vehicle_id' => ['nullable', 'integer'],
            'driver_name' => ['nullable', 'string', 'max:100'],
            'crew' => ['nullable', 'array', 'max:20'],
            'crew.*' => ['nullable', 'string', 'max:100'],
            'carrier' => ['nullable', 'string', 'max:150'],
        ], ['delivery_date.required' => 'เลือกวันส่ง', 'delivery_time.date_format' => 'ใส่เวลาแบบ 09:00']);
        $moved = $delivery->status === ProjectDelivery::SCHEDULED;
        $this->flow->schedule($delivery, $data, $request->user());

        return $this->done($request, $moved ? 'เลื่อนวันส่งแล้ว' : 'กำหนดวันส่งแล้ว');
    }

    public function reject(Request $request, ProjectDelivery $delivery)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:100'], 'note' => ['nullable', 'string', 'max:500']], ['reason.required' => 'เลือกเหตุผลที่ไม่รับงาน']);
        $this->flow->reject($delivery, $data['reason'], $data['note'] ?? null, $request->user());

        return $this->done($request, 'ส่งกลับให้ PM แล้ว');
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
        $left = (int) $today->diffInDays($d->lastDay(), false);

        return [
            'id' => $d->id, 'status' => $d->status, 'state' => $d->stateLabel(), 'color' => $d->stateColor(),
            'project' => $d->project->project_no, 'name' => $d->project->project_name, 'round' => $d->round,
            'cabinets' => collect($d->cabinet_ids ?? [])->map(fn ($id) => ['id' => $id, 'name' => $names[$id] ?? 'ตู้ที่ลบแล้ว'])->values()->all(),
            'site' => (string) $d->site, 'contact' => collect([$d->contact_name, $d->contact_phone])->filter()->implode(' '), 'note' => (string) $d->note,
            'method' => $d->method, 'transport' => $d->transport() ?? 'ยังไม่มีรถ', 'vehicle_id' => $d->vehicle_id ? (string) $d->vehicle_id : '',
            'driver_name' => (string) $d->driver_name, 'crew' => $d->crew ?? [], 'carrier' => (string) $d->carrier,
            // what PM asked for, and whether the vehicle picked is of another kind
            'vehicle_type' => (string) $d->vehicle_type, 'crew_size' => (int) $d->crew_size, 'request' => $d->request(), 'other' => $d->otherType(),
            'people' => $d->peopleText(), 'short' => $d->crew_size && count($d->people()) < $d->crew_size,
            'from' => $d->window_from->format('Y-m-d'), 'to' => $d->window_to->format('Y-m-d'),
            'window' => ProjectDelivery::range($d->window_from, $d->window_to), 'sender' => (string) $d->sender?->name,
            'date' => $d->delivery_date?->format('Y-m-d') ?? '', 'time' => (string) $d->delivery_time, 'when' => $d->when(),
            // the window running out (a day not picked) / the day passed (not arrived)
            'left' => $left, 'late' => $d->status !== ProjectDelivery::DELIVERED && $left < 0, 'soon' => $d->isOpen() && $left >= 0 && $left <= 2,
            'departed' => $d->departed_at?->format('d/m H:i'), 'delivered' => $d->delivered_at ? $d->delivered_at->thaiDate().($d->delivered_at->format('H:i') !== '00:00' ? ' '.$d->delivered_at->format('H:i').' น.' : '') : '',
            'delivered_date' => $d->delivered_at?->format('Y-m-d') ?? '', 'delivered_time' => $d->delivered_at && $d->delivered_at->format('H:i') !== '00:00' ? $d->delivered_at->format('H:i') : '',
            'receiver' => (string) $d->receiver, 'doc_no' => (string) $d->doc_no, 'issue' => (string) $d->issue,
            'rejected' => $d->status === ProjectDelivery::REJECTED ? trim($d->rejected_reason.($d->rejected_note ? ' · '.$d->rejected_note : '').' · โดย '.$d->rejecter?->name.' '.$d->rejected_at?->format('d/m H:i')) : '',
            'urls' => ['schedule' => route('deliveries.schedule', $d), 'reject' => route('deliveries.reject', $d), 'depart' => route('deliveries.depart', $d),
                'deliver' => route('deliveries.deliver', $d), 'back' => route('deliveries.back', $d)],
        ];
    }

    /** vehicle id => day => what it already carries that day ("SO-690104 รอบ 1"), for the warning when picking a day. */
    private function busy(Collection $all): array
    {
        $out = [];
        foreach ($all->filter(fn ($d) => $d->vehicle_id && in_array($d->status, [ProjectDelivery::SCHEDULED, ProjectDelivery::DEPARTED], true)) as $d) {
            for ($day = $d->delivery_date->copy(); $day->lte($d->lastDay()); $day->addDay()) {
                $out[$d->vehicle_id][$day->format('Y-m-d')][] = ['id' => $d->id, 'label' => $d->project->project_no.' รอบ '.$d->round];
            }
        }

        return $out;
    }

    private function done(Request $request, string $message)
    {
        return $request->wantsJson() ? response()->json(['message' => $message]) : back()->with('success', $message);
    }
}
