<?php

namespace Database\Seeders;

use App\Models\CabinetTemplate;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\CabinetTemplateService;
use App\Services\ProgressService;
use Illuminate\Database\Seeder;

class DemoProjectSeeder extends Seeder
{
    public function run(): void
    {
        $pm = User::where('role', User::ROLE_PROJECT_MANAGER)->first();
        $sales = User::where('role', User::ROLE_SALES)->first();
        $admin = User::where('role', User::ROLE_ADMIN)->first();

        $template = CabinetTemplate::with('taskTemplates.subtaskTemplates.checklistTemplates')
            ->where('code', 'STANDARD')->firstOrFail();

        $project = Project::updateOrCreate(
            ['project_no' => 'PJ-690001'],
            [
                'project_name' => 'MDB Factory Expansion',
                'customer_name' => 'ABC Company',
                'po_no' => 'PO-ABC-001',
                'sales_order_no' => 'SO-2569-001',
                'owner_id' => $admin?->id,
                'sales_person_id' => $sales?->id,
                'project_manager_id' => $pm?->id,
                'start_date' => '2026-09-01',
                'due_date' => '2026-10-30',
                'description' => 'Main Distribution Board factory expansion project for ABC Company.',
                'status' => Project::STATUS_IN_PROGRESS,
                'priority' => Project::PRIORITY_HIGH,
            ]
        );

        $this->seedProjectTasks($project, $pm);

        $cabinetsData = [
            ['mo_no' => 'MO-690001', 'cabinet_name' => 'MDB-01', 'cabinet_type' => 'MDB', 'due_date' => '2026-10-20', 'checklist_ratio' => 1.0],
            ['mo_no' => 'MO-690002', 'cabinet_name' => 'MDB-02', 'cabinet_type' => 'MDB', 'due_date' => '2026-10-22', 'checklist_ratio' => 0.75],
            ['mo_no' => 'MO-690003', 'cabinet_name' => 'DB-01', 'cabinet_type' => 'DB', 'due_date' => '2026-10-25', 'checklist_ratio' => 0.4],
            ['mo_no' => 'MO-690004', 'cabinet_name' => 'MCC-01', 'cabinet_type' => 'MCC', 'due_date' => '2026-10-28', 'checklist_ratio' => 0.15],
        ];

        $templateService = app(CabinetTemplateService::class);
        $progressService = app(ProgressService::class);

        foreach ($cabinetsData as $index => $data) {
            $existing = $project->cabinets()->where('mo_no', $data['mo_no'])->first();
            if ($existing) {
                continue;
            }

            $cabinet = $project->cabinets()->create([
                'mo_no' => $data['mo_no'],
                'cabinet_name' => $data['cabinet_name'],
                'cabinet_type' => $data['cabinet_type'],
                'quantity' => 1,
                'start_date' => '2026-09-05',
                'due_date' => $data['due_date'],
                'status' => 'not_started',
                'progress' => 0,
                'sequence' => $index,
            ]);

            $templateService->applyToCabinet($cabinet, $template);

            $this->applyDemoProgress($cabinet, $data['checklist_ratio'], $progressService);
        }

        $this->command?->info('Demo project PJ-690001 seeded with 4 cabinets.');
    }

    private function seedProjectTasks(Project $project, ?User $pm): void
    {
        $tasks = [
            ['name' => 'รับ PO', 'progress' => 100, 'status' => ProjectTask::STATUS_COMPLETED],
            ['name' => 'ตรวจสอบข้อมูลโครงการ', 'progress' => 100, 'status' => ProjectTask::STATUS_COMPLETED],
            ['name' => 'Kick-off', 'progress' => 100, 'status' => ProjectTask::STATUS_COMPLETED],
            ['name' => 'ตรวจสอบ Specification', 'progress' => 80, 'status' => ProjectTask::STATUS_IN_PROGRESS],
            ['name' => 'เตรียมเอกสาร', 'progress' => 50, 'status' => ProjectTask::STATUS_IN_PROGRESS],
            ['name' => 'นัดหมายลูกค้า', 'progress' => 0, 'status' => ProjectTask::STATUS_NOT_STARTED],
            ['name' => 'เตรียมส่งมอบ', 'progress' => 0, 'status' => ProjectTask::STATUS_NOT_STARTED],
        ];

        foreach ($tasks as $i => $task) {
            $project->projectTasks()->updateOrCreate(
                ['name' => $task['name']],
                [
                    'owner_id' => $pm?->id,
                    'status' => $task['status'],
                    'progress' => $task['progress'],
                    'priority' => 'normal',
                    'sequence' => $i,
                ]
            );
        }
    }

    private function applyDemoProgress($cabinet, float $ratio, ProgressService $progressService): void
    {
        if ($ratio <= 0) {
            return;
        }

        $checklists = $cabinet->tasks()
            ->with('subtasks.checklists')
            ->get()
            ->pluck('subtasks')
            ->flatten()
            ->pluck('checklists')
            ->flatten();

        $total = $checklists->count();
        $toComplete = (int) round($total * $ratio);

        $checklists->take($toComplete)->each(function ($checklist) use ($progressService) {
            $progressService->toggleChecklist($checklist, true);
        });
    }
}
