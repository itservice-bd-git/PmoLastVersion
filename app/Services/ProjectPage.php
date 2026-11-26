<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\Project;
use App\Models\ProjectDateReason;
use App\Models\ProjectDelivery;
use App\Models\ProjectFat;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Everything the project page (projects.show) reads from the database, worked out before the view renders it: the header's
 * FAT / delivery tiles and followers, the ข้อมูลโครงการ tab, the วัน FAT panel (_fat), the การจัดส่ง panel (_delivery) and
 * the เลื่อน / แก้วัน windows (_dates, the cabinet page's too). The views only lay it out (CLAUDE.md "Views do not query").
 */
class ProjectPage
{
    public function __construct(private ProjectDates $dates) {}

    /** All of the page's data, for ProjectController::show. */
    public function build(Project $project, User $user): array
    {
        $fats = $project->fats()->get();   // the header, the status line and the FAT panel share one read
        $canAppt = $user->canDispatchWork() && ! $project->isLocked();

        return [
            'hdrFat' => $fats->last(),
            'hdrRounds' => $project->deliveries()->with('vehicles.vehicle')
                ->when(! DeliveryFlow::canBook($user), fn ($q) => $q->where('status', '!=', ProjectDelivery::DRAFT))->get(),
            'hdrCanAppt' => $canAppt,
            'followers' => $project->followers()->orderBy('name')->get(['users.id', 'name']),
            // ข้อมูลโครงการ: the cabinets' sizes, most used first; เลื่อน / แก้วัน: how many rounds, and the button
            'sizes' => $project->cabinets()->whereNotNull('size')->where('size', '!=', '')->pluck('size')->countBy()->sortDesc(),
            'dates' => $this->dates->summary($project),
            'canDates' => $canAppt,
        ] + $this->fat($project, $fats, $canAppt)
            + $this->delivery($project, $canAppt)
            + $this->dateWindow($project, $canAppt);
    }

    /** วัน FAT (_fat): its rounds, the cabinets to pick, the move history, and (Admin / PM) the window's starting values. */
    public function fat(Project $project, Collection $fats, bool $canFat): array
    {
        $fat = $fats->last();

        return [
            'fats' => $fats,
            'canFat' => $canFat,
            'fatCabinets' => $project->cabinets()->orderBy('sequence')->orderBy('id')->get(['id', 'mo_no', 'cabinet_name']),
            // edit the latest round, or book a new one ('new'): the only two the panel opens
            'fatForms' => $canFat ? ['new' => ProjectFat::formData($project, null, $fat)] + ($fat ? [$fat->id => ProjectFat::formData($project, $fat, $fat)] : []) : [],
            'fatMoves' => EventDates::history($project, EventDates::FAT),
        ];
    }

    /** การจัดส่ง (_delivery): every round, the photos of those that arrived, the window's data and each round's starting values. */
    public function delivery(Project $project, bool $canDeliver): array
    {
        $rounds = $project->deliveries()->with('vehicles.vehicle', 'rejecter', 'biller')->get();
        // a round still to go opens in the window (draft, sent back, waiting, scheduled); one that left or arrived does not
        $editable = $rounds->whereNotIn('status', [ProjectDelivery::DEPARTED, ProjectDelivery::DELIVERED]);

        return [
            'rounds' => $rounds,
            'roundFiles' => ProjectDelivery::filesOf($rounds->where('status', ProjectDelivery::DELIVERED)->pluck('id')),   // sent with ส่งถึงแล้ว
            'canDeliver' => $canDeliver,
            'win' => DeliveryWindow::data($project),
            'deliveryForms' => $canDeliver
                ? ['new' => DeliveryWindow::form($project, null)] + $editable->mapWithKeys(fn ($d) => [$d->id => DeliveryWindow::form($project, $d)])->all()
                : [],
            'dlMoves' => EventDates::history($project, EventDates::DELIVERY),
        ];
    }

    /**
     * เลื่อน / แก้วัน (_dates) of a project or a cabinet: the reasons to pick, the FAT / delivery rounds that go along (a
     * project's own), and the departments a postponement tells. Only Admin / PM ($canDates) get the form, so only they need it.
     */
    public function dateWindow(Project|Cabinet $subject, bool $canDates): array
    {
        return [
            'reasons' => $canDates ? ProjectDateReason::where('is_active', true)->orderBy('sequence')->orderBy('id')->get(['id', 'name']) : collect(),
            'linked' => $canDates && $subject instanceof Project ? ProjectDates::linkedEvents($subject) : collect(),
            'notifyDepts' => $canDates ? ProjectDates::departments($subject)->pluck('name') : collect(),
        ];
    }
}
