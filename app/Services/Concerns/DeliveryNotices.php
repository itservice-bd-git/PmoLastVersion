<?php

namespace App\Services\Concerns;

use App\Models\ActivityLog;
use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\NotificationPreference;
use App\Models\Project;
use App\Models\User;
use App\Notifications\PmoNotice;
use App\Services\ChecklistNoticeRules;
use App\Services\DeliveryFlow;
use App\Services\HandoffService;
use App\Services\PlanningBoard;
use App\Services\PlanningSync;
use App\Services\ProjectFollowers;
use App\Services\StatusReport;
use App\Services\WebPushService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * NotificationService · การจัดส่ง: what the delivery department and the project's PM hear about a round (DeliveryFlow).
 * Part of NotificationService (a trait of it): who hears, the channels and the de-duplication are there.
 */
trait DeliveryNotices
{
    /** การจัดส่ง (DeliveryFlow): the delivery department = the active people of every department allowed "จัดการการจัดส่ง" (Access). */
    public function deliveryDepartment(?User $except = null): Collection
    {
        $ids = Department::where('is_active', true)->pluck('id')->filter(fn ($id) => \App\Support\Access::departmentAllows($id, 'edit.delivery'))->values()->all();

        return $ids ? User::ofDepartment($ids)->where('is_active', true)->when($except, fn ($q) => $q->where('id', '!=', $except->id))->get() : collect();
    }

    /**
     * A round for the delivery department to act on (sent / sent again / its window moved): "ต้องทำ" until they pick a day.
     * $todo false = only news (PM changed a round that already has its day).
     */
    public function deliveryForDepartment(\App\Models\ProjectDelivery $d, User $actor, string $title, bool $todo = true, bool $tellActor = false): int
    {
        $project = $d->project;

        // $tellActor: it was not the actor's own doing (the driver's link: the one who sent it hears it too)
        return $this->send($this->deliveryDepartment($tellActor ? null : $actor), new PmoNotice(
            $todo ? 'delivery_todo' : 'delivery',
            $title,
            $this->deliveryText($d),
            route('deliveries.index', ['round' => $d->id]),
            projectId: $project->id,
            group: $todo ? null : "delivery:{$d->id}",
        ));
    }

    /**
     * The delivery department moved a round on (picked its day, changed the vehicle, sent it back, it left, it arrived): the
     * project's people in charge and whoever sent it hear it (nobody = every Admin / PM). Sent back / a problem on site = "ต้องทำ".
     */
    public function deliveryForManager(\App\Models\ProjectDelivery $d, User $actor, string $title, ?string $extra = null, bool $todo = false): int
    {
        $project = $d->project;
        $people = $this->peopleInCharge($project, $actor)
            ->merge(User::where('id', $d->sent_by)->where('is_active', true)->where('id', '!=', $actor->id)->get())->unique('id')->values();

        return $this->send($people->isNotEmpty() ? $people : $this->pmoPeople($actor), new PmoNotice(
            $todo ? 'delivery_rejected' : 'delivery',
            $title,
            $this->deliveryText($d).($extra ? ' — '.$extra : ''),
            route('projects.show', $project).'#delivery',
            projectId: $project->id,
            group: $todo ? null : "delivery:{$d->id}",
        ));
    }

    /**
     * แจ้งเปิดบิล (DeliveryFlow::bill): the billing department chosen at Settings > รถขนส่ง hears it as "ต้องทำ"; none
     * chosen = nobody besides PM (who hears it from bill()).
     */
    public function deliveryBilling(\App\Models\ProjectDelivery $d, User $actor): int
    {
        $dept = (int) \App\Models\AppSetting::get('delivery_billing_department');
        $people = $dept ? User::ofDepartment([$dept])->where('is_active', true)->where('id', '!=', $actor->id)->get() : collect();

        // the project page for whoever may open it; the rest (billing has no work in the project) the notice list
        [$see, $not] = $people->partition(fn (User $u) => \App\Services\WorkScope::canSeeProject($d->project, $u));
        $notice = fn (string $url) => new PmoNotice('delivery_todo', 'แจ้งเปิดบิล', $this->deliveryText($d).' · กดรับทราบเมื่อเปิดบิลแล้ว', $url, projectId: $d->project_id);

        return ($see->isEmpty() ? 0 : $this->send($see->values(), $notice(route('projects.show', $d->project).'#delivery')))
            + ($not->isEmpty() ? 0 : $this->send($not->values(), $notice(route('notifications.index'))));
    }

    /** "SO-690112 MDB โรงงานบางนา · รอบ 2 · 3 ตู้ · 20/11/2569 · 09:00 น." */
    private function deliveryText(\App\Models\ProjectDelivery $d): string
    {
        return "{$d->project->project_no} {$d->project->project_name} · รอบ {$d->round} · ".$d->cabinetsText().' · '.$d->when();
    }
}
