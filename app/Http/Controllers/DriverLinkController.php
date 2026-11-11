<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\ProjectDelivery;
use App\Models\User;
use App\Services\DeliveryFlow;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * ลิงก์คนขับ: the delivery department shares a round that has left the factory (LINE, SMS) and the driver - no login -
 * takes the photos at the site and presses ส่งถึงแล้ว; the round arrives with them, as from งานจัดส่ง (DeliveryFlow::deliver,
 * the one who shared the link as the actor, "ผ่านลิงก์คนขับ" in the history) - รอตรวจ: PM hears it only once the delivery
 * department has checked it (DeliveryFlow::review).
 *
 * The link is signed (relative: the same on any host / scheme) and names the round and who shared it, so neither can be
 * changed. No expiry: it works while the round is ออกจากโรงงาน and stops by itself once it arrives or is taken back.
 */
class DriverLinkController extends Controller
{
    public function __construct(private DeliveryFlow $flow) {}

    /** The link to share (absolute, for the card's ส่งลิงก์ให้คนขับ). */
    public static function urlFor(ProjectDelivery $d, User $by): string
    {
        return url(URL::signedRoute('driver.show', ['delivery' => $d->id, 'by' => $by->id], absolute: false));
    }

    public function show(Request $request, ProjectDelivery $delivery)
    {
        $d = $delivery->load('project');

        return view('driver.show', [
            'd' => $d,
            'state' => $this->state($d, $request),
            // what the driver checks against on the truck: the cabinet's name, its MO, what it is, how many
            'cabinets' => Cabinet::whereIn('id', $d->cabinet_ids ?? [])->orderBy('id')->get(['id', 'mo_no', 'cabinet_name', 'cabinet_type', 'size', 'quantity'])
                ->map(fn ($c) => ['name' => (string) ($c->cabinet_name ?: $c->mo_no), 'mo' => $c->cabinet_name ? (string) $c->mo_no : '',
                    'detail' => collect([$c->cabinet_type, $c->size ? 'ขนาด '.$c->size : null])->filter()->implode(' · '), 'qty' => max(1, (int) $c->quantity)])->all(),
        ]);
    }

    public function store(Request $request, ProjectDelivery $delivery)
    {
        if ($this->state($delivery, $request) !== 'open') {
            return redirect()->to($request->fullUrl());
        }
        $data = $request->validate([
            'issue' => ['nullable', 'string', 'max:500'],
            'files' => ['required', 'array', 'min:1', 'max:'.ProjectDelivery::FILES_MAX],
            'files.*' => ['file', 'max:20480', 'mimes:jpg,jpeg,png,pdf'],
        ], ['files.required' => 'ถ่ายรูปอย่างน้อย 1 รูป', 'files.min' => 'ถ่ายรูปอย่างน้อย 1 รูป', 'files.max' => 'ส่งได้ครั้งละไม่เกิน '.ProjectDelivery::FILES_MAX.' รูป',
            'files.*.max' => 'รูป :attribute ใหญ่เกิน 20 MB', 'files.*.mimes' => 'ส่งได้เฉพาะรูป JPG / PNG หรือ PDF', 'files.*.uploaded' => 'อัปโหลดไม่สำเร็จ (รูปอาจใหญ่เกินไป)'],
            ['files.*' => 'ที่ส่ง']);

        $actor = User::find($request->integer('by'));
        $now = now();
        $this->flow->deliver($delivery, [
            'delivered_date' => $now->format('Y-m-d'), 'delivered_time' => $now->format('H:i'),
            'issue' => $data['issue'] ?? null, 'cabinet_ids' => $delivery->cabinet_ids ?? [],
        ], $actor, $request->file('files', []), 'ผ่านลิงก์คนขับ', review: true);
        // รอตรวจ: the delivery department looks at it first (the one who shared too: it was not their own doing); PM hears it
        // once they have (DeliveryFlow::review)
        app(NotificationService::class)->deliveryForDepartment($delivery->fresh('project'), $actor,
            'คนขับแจ้งส่งถึง · '.count($request->file('files', [])).' รูป · รอตรวจ', todo: true, tellActor: true);

        return redirect()->to($request->fullUrl());
    }

    /** open: the driver can send · wait: not left yet · done: arrived · closed: the link no longer works (taken back, the sharer left) */
    private function state(ProjectDelivery $d, Request $request): string
    {
        $by = User::find($request->integer('by'));
        if (! $by || ! $by->is_active || ! DeliveryFlow::canDeliver($by) || ! $d->project || $d->project->isLocked()) {
            return 'closed';
        }

        return match ($d->status) {
            ProjectDelivery::DEPARTED => 'open',
            ProjectDelivery::DELIVERED => 'done',
            ProjectDelivery::SCHEDULED => 'wait',
            default => 'closed',
        };
    }
}
